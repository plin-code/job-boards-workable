<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-workable/main/art/banner.png" alt="Job Boards Workable">
</p>

# Job Boards Workable

Workable connector for the [plin-code](https://github.com/plin-code) job boards family. It reads the public Workable job board widget, which needs no credentials and returns a whole board in one request:

```
GET https://apply.workable.com/api/v1/widget/accounts/{slug}
{ "name": "Acme", "description": "...", "jobs": [ { "shortcode": "ABC123", "title": "Backend Engineer", ... } ] }
```

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-workable
```

## Framework agnostic on purpose

`WorkableClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Workable\WorkableClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new WorkableClient($http);

$jobs = $client->fetchJobsForCompany('acme');       // list<JobPostingDTO>
$name = $client->validateSlug('acme');              // ?string, the account name
$about = $client->fetchCompanyDescription('acme');  // ?string
```

`WorkableServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\Workable\WorkableClient;

$client = app(WorkableClient::class);

foreach ($client->fetchJobsForCompany('acme') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the base URL, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-workable-config
```

```php
'base_url'       => env('JOB_BOARDS_WORKABLE_BASE_URL', WorkableClient::API_BASE_URL),
'timeout'        => env('JOB_BOARDS_WORKABLE_TIMEOUT', 30),
'lookup_timeout' => env('JOB_BOARDS_WORKABLE_LOOKUP_TIMEOUT', 15),
'headers'        => ['Accept' => 'application/json'],
```

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Mapping

| `JobPostingDTO` | Workable field |
| --- | --- |
| `externalId` | `shortcode`, falling back to `id`, then `''` |
| `title` | `title`, falling back to `'Untitled Position'` |
| `location` | `city` and `country` joined with `', '`, empty parts dropped, `null` when both are missing |
| `url` | `url`, falling back to `shortlink`, then `''` |
| `department` | `department`, or `null` |
| `rawPayload` | the untouched job object |

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| non 2xx status | `warning` | `Workable API request failed` |
| payload has no `jobs` array | `warning` | `Workable API response missing jobs array` |
| DNS failure, refused connection, timeout | `error` | `Workable API connection error` |
| unreadable body, unexpected shape | `error` | `Unexpected error fetching Workable jobs` |

Every record carries `company_slug`. With no logger passed, a `NullLogger` is used and everything is silent.

`validateSlug()` and `fetchCompanyDescription()` return `null` for every failure and log nothing. That is intentional: neither a 404 nor a dropped connection proves a slug is good, and callers use these to validate user input.

## Timeouts

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so the configured 30 and 15 seconds are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

During local development this package resolves core through a path repository:

```json
"repositories": [
    { "type": "path", "url": "../job-boards-core", "options": { "symlink": true } }
]
```

That block and the `"plin-code/job-boards-core": "*"` constraint are **for local development only**. Once core is published, drop the `repositories` block and pin the real constraint:

```json
"require": {
    "plin-code/job-boards-core": "^0.1"
}
```

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against a faked PSR-18 client and boots no framework. `tests/Feature` boots Testbench and covers the service provider only.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
