<?php

declare(strict_types=1);

use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;
use PlinCode\JobBoards\Workable\WorkableClient;

function workableClient(FakePsrClient $fake, ?RecordingLogger $logger = null): WorkableClient
{
    return new WorkableClient($fake->asHttpClient(), logger: $logger);
}

/**
 * @param  list<array<string, mixed>>  $jobs
 */
function withJobs(array $jobs): FakePsrClient
{
    return (new FakePsrClient)->respondWithJson(['jobs' => $jobs]);
}

it('fetches jobs for a valid company slug', function (): void {
    $fake = withJobs([
        [
            'shortcode' => 'ABC123',
            'title' => 'Backend Engineer',
            'city' => 'Paris',
            'country' => 'France',
            'department' => 'Engineering',
            'url' => 'https://apply.workable.com/testco/j/ABC123',
        ],
        [
            'shortcode' => 'DEF456',
            'title' => 'Frontend Engineer',
            'city' => 'Amsterdam',
            'country' => 'Netherlands',
            'department' => 'Design',
            'url' => 'https://apply.workable.com/testco/j/DEF456',
        ],
    ]);

    $jobs = workableClient($fake)->fetchJobsForCompany('testco');

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('ABC123')
        ->and($jobs[0]->title)->toBe('Backend Engineer')
        ->and($jobs[0]->location)->toBe('Paris, France')
        ->and($jobs[0]->url)->toBe('https://apply.workable.com/testco/j/ABC123')
        ->and($jobs[0]->department)->toBe('Engineering')
        ->and($jobs[1]->externalId)->toBe('DEF456')
        ->and($fake->lastUri())->toBe('https://apply.workable.com/api/v1/widget/accounts/testco');
});

it('returns an empty list for an empty jobs array', function (): void {
    expect(workableClient(withJobs([]))->fetchJobsForCompany('testco'))->toBe([]);
});

it('returns an empty list on a failed http response', function (): void {
    $fake = (new FakePsrClient)->respondWith(500, 'Server Error');
    $logger = new RecordingLogger;

    expect(workableClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['Workable API request failed'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe([
            'company_slug' => 'broken',
            'status' => 500,
            'body' => 'Server Error',
        ]);
});

it('returns an empty list on a connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(workableClient($fake, $logger)->fetchJobsForCompany('timeout'))->toBe([])
        ->and($logger->messages())->toBe(['Workable API connection error'])
        ->and($logger->levels())->toBe(['error'])
        ->and($logger->records[0]['context']['company_slug'])->toBe('timeout')
        ->and($logger->records[0]['context']['error'])->toContain('connection refused');
});

it('returns an empty list when the jobs key is missing', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['error' => 'not found']);
    $logger = new RecordingLogger;

    expect(workableClient($fake, $logger)->fetchJobsForCompany('invalid'))->toBe([])
        ->and($logger->messages())->toBe(['Workable API response missing jobs array'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context']['response'])->toBe(['error' => 'not found']);
});

it('handles location with only city', function (): void {
    $jobs = workableClient(withJobs([
        ['shortcode' => 'ABC123', 'title' => 'Engineer', 'city' => 'Berlin', 'url' => 'https://example.com'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBe('Berlin');
});

it('handles location with only country', function (): void {
    $jobs = workableClient(withJobs([
        ['shortcode' => 'ABC123', 'title' => 'Engineer', 'country' => 'Germany', 'url' => 'https://example.com'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBe('Germany');
});

it('handles missing location fields', function (): void {
    $jobs = workableClient(withJobs([
        ['shortcode' => 'ABC123', 'title' => 'Remote Engineer', 'url' => 'https://example.com'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBeNull();
});

it('drops empty city and country strings instead of joining them', function (): void {
    $jobs = workableClient(withJobs([
        ['shortcode' => 'ABC123', 'title' => 'Engineer', 'city' => '', 'country' => 'Italy', 'url' => 'https://example.com'],
        ['shortcode' => 'DEF456', 'title' => 'Engineer', 'city' => '', 'country' => '', 'url' => 'https://example.com'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBe('Italy')
        ->and($jobs[1]->location)->toBeNull();
});

it('uses shortlink when url is missing', function (): void {
    $jobs = workableClient(withJobs([
        ['shortcode' => 'ABC123', 'title' => 'Engineer', 'shortlink' => 'https://wkbl.co/ABC123'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->url)->toBe('https://wkbl.co/ABC123');
});

it('falls back to an empty url and a placeholder title', function (): void {
    $jobs = workableClient(withJobs([
        ['shortcode' => 'ABC123'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->url)->toBe('')
        ->and($jobs[0]->title)->toBe('Untitled Position')
        ->and($jobs[0]->department)->toBeNull()
        ->and($jobs[0]->rawPayload)->toBe(['shortcode' => 'ABC123']);
});

it('falls back to the numeric id when shortcode is absent', function (): void {
    $jobs = workableClient(withJobs([
        ['id' => 987, 'title' => 'Engineer'],
        ['title' => 'No identifier at all'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->externalId)->toBe('987')
        ->and($jobs[1]->externalId)->toBe('');
});

it('returns an empty list when the body is not json', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<html>maintenance</html>');
    $logger = new RecordingLogger;

    expect(workableClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Workable jobs'])
        ->and($logger->levels())->toBe(['error']);
});

it('returns an empty list when a jobs entry is not an object', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['jobs' => ['not-an-object']]);
    $logger = new RecordingLogger;

    expect(workableClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Workable jobs']);
});

it('validates a valid slug and returns the company name', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['name' => 'TestCo Inc', 'jobs' => []]);

    expect(workableClient($fake)->validateSlug('testco'))->toBe('TestCo Inc');
});

it('returns null for invalid slug validation', function (): void {
    $fake = (new FakePsrClient)->respondWith(404, 'Not Found');

    expect(workableClient($fake)->validateSlug('nonexistent'))->toBeNull();
});

it('returns null for slug validation on connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(workableClient($fake, $logger)->validateSlug('timeout'))->toBeNull()
        ->and($logger->records)->toBe([]);
});

it('returns null when validate slug response is missing name', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['jobs' => []]);

    expect(workableClient($fake)->validateSlug('testco'))->toBeNull();
});

it('returns null when the validate slug response is not json or the name is empty', function (): void {
    expect(workableClient((new FakePsrClient)->respondWith(200, 'not json'))->validateSlug('testco'))->toBeNull()
        ->and(workableClient((new FakePsrClient)->respondWithJson(['name' => '']))->validateSlug('testco'))->toBeNull()
        ->and(workableClient((new FakePsrClient)->respondWithJson(['name' => 42]))->validateSlug('testco'))->toBeNull();
});

it('fetches the company description', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['name' => 'TestCo', 'description' => 'We build things']);

    expect(workableClient($fake)->fetchCompanyDescription('testco'))->toBe('We build things');
});

it('returns null from fetchCompanyDescription when it is absent, empty or unreachable', function (): void {
    expect(workableClient((new FakePsrClient)->respondWithJson(['name' => 'TestCo']))->fetchCompanyDescription('testco'))->toBeNull()
        ->and(workableClient((new FakePsrClient)->respondWithJson(['description' => '']))->fetchCompanyDescription('testco'))->toBeNull()
        ->and(workableClient((new FakePsrClient)->respondWith(404, 'Not Found'))->fetchCompanyDescription('nope'))->toBeNull()
        ->and(workableClient((new FakePsrClient)->throwNetworkError())->fetchCompanyDescription('timeout'))->toBeNull();
});

it('asks for 30 seconds when listing and 15 when looking up', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(['jobs' => []])
        ->respondWithJson(['name' => 'TestCo'])
        ->respondWithJson(['description' => 'Hello']);

    $client = workableClient($fake);
    $client->fetchJobsForCompany('testco');
    $client->validateSlug('testco');
    $client->fetchCompanyDescription('testco');

    expect($fake->appliedTimeouts)->toBe([30.0, 15.0, 15.0]);
});

it('percent encodes the slug in the url', function (): void {
    $fake = withJobs([]);

    workableClient($fake)->fetchJobsForCompany('a b/../c');

    expect($fake->lastUri())->toBe('https://apply.workable.com/api/v1/widget/accounts/a%20b%2F..%2Fc');
});

it('is safe with no logger at all', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();

    expect((new WorkableClient($fake->asHttpClient()))->fetchJobsForCompany('testco'))->toBe([]);
});

it('accepts a custom base url', function (): void {
    $fake = withJobs([]);

    (new WorkableClient($fake->asHttpClient(), 'https://fixtures.test/accounts/'))->fetchJobsForCompany('testco');

    expect($fake->lastUri())->toBe('https://fixtures.test/accounts/testco');
});
