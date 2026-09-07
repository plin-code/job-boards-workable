<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Workable;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\InvalidResponseException;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the public Workable job board widget:
 *
 *   GET https://apply.workable.com/api/v1/widget/accounts/{slug}
 *   { "name": "Acme", "description": "...", "jobs": [ { "shortcode": ..., ... } ] }
 *
 * No credentials, no pagination: one request returns the whole board.
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see WorkableServiceProvider} is the only Laravel aware file.
 */
final class WorkableClient implements JobBoardClient
{
    public const string API_BASE_URL = 'https://apply.workable.com/api/v1/widget/accounts';

    /**
     * Listing a whole board can be slow, so it gets a longer budget than the
     * cheap name and description lookups below.
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl = self::API_BASE_URL,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        try {
            // get() rather than tryGet() so the transport error message survives
            // into the log. tryGet() would flatten it to a null.
            $response = $this->http->withTimeout($this->timeout)->get($this->endpoint($slug));

            if ($response->failed()) {
                $this->logger->warning('Workable API request failed', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $jobs = $response->json('jobs');

            if (! is_array($jobs)) {
                $this->logger->warning('Workable API response missing jobs array', [
                    'company_slug' => $slug,
                    'response' => $response->json(),
                ]);

                return [];
            }

            $postings = [];

            foreach ($jobs as $job) {
                if (! is_array($job)) {
                    throw InvalidResponseException::unexpectedShape(
                        $response->url(),
                        'jobs.*',
                        get_debug_type($job),
                    );
                }

                /** @var array<string, mixed> $job */
                $postings[] = $this->mapToDTO($job);
            }

            return $postings;
        } catch (TransportException $e) {
            $this->logger->error('Workable API connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching Workable jobs', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Returns the account name when the slug resolves, null otherwise. A 404 and
     * a dead connection are deliberately indistinguishable here: neither proves
     * the slug is good.
     */
    public function validateSlug(string $slug): ?string
    {
        return $this->lookup($slug, 'name');
    }

    public function fetchCompanyDescription(string $slug): ?string
    {
        return $this->lookup($slug, 'description');
    }

    /**
     * Read one non empty string field out of the account payload, or null if
     * anything at all goes wrong.
     */
    private function lookup(string $slug, string $key): ?string
    {
        try {
            // tryGet() here: these two are silent by contract, so there is no
            // message to keep.
            $response = $this->http->withTimeout($this->lookupTimeout)->tryGet($this->endpoint($slug));

            if ($response === null || $response->failed()) {
                return null;
            }

            $value = $response->json($key);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function endpoint(string $slug): string
    {
        return sprintf('%s/%s', rtrim($this->baseUrl, '/'), rawurlencode($slug));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapToDTO(array $data): JobPostingDTO
    {
        $shortcode = $data['shortcode'] ?? null;
        $id = $data['id'] ?? null;
        $title = $data['title'] ?? null;
        $url = $data['url'] ?? null;
        $shortlink = $data['shortlink'] ?? null;
        $department = $data['department'] ?? null;

        $externalId = match (true) {
            is_scalar($shortcode) => (string) $shortcode,
            is_scalar($id) => (string) $id,
            default => '',
        };

        return new JobPostingDTO(
            externalId: $externalId,
            title: is_string($title) ? $title : 'Untitled Position',
            location: $this->location($data),
            url: match (true) {
                is_string($url) => $url,
                is_string($shortlink) => $shortlink,
                default => '',
            },
            department: is_string($department) ? $department : null,
            rawPayload: $data,
        );
    }

    /**
     * "Paris, France", "Berlin", "Germany" or null. Workable sends city and
     * country separately and either may be absent or empty.
     *
     * @param  array<string, mixed>  $data
     */
    private function location(array $data): ?string
    {
        $parts = [];

        foreach (['city', 'country'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
