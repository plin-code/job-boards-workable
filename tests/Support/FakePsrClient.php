<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Workable\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Http\SupportsTimeout;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client that answers from a queue and records what it was asked for.
 * This is the replacement for Laravel's Http::fake(): queue the responses the
 * provider would give, then assert on ->uris() afterwards.
 *
 * It also implements SupportsTimeout so tests can assert that the connector
 * asked for the timeout it says it does.
 */
final class FakePsrClient implements ClientInterface, SupportsTimeout
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<float> */
    public array $appliedTimeouts = [];

    /** @var list<ResponseInterface> */
    private array $queue = [];

    private bool $throwNetworkError = false;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function respondWith(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new PsrResponse($status, $headers, $body);

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function respondWithJson(array $payload, int $status = 200): self
    {
        return $this->respondWith($status, json_encode($payload, JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    public function throwNetworkError(): self
    {
        $this->throwNetworkError = true;

        return $this;
    }

    public function withTimeout(float $seconds): ClientInterface
    {
        $this->appliedTimeouts[] = $seconds;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->throwNetworkError) {
            throw new FakeNetworkException($request);
        }

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('No queued response for '.$request->getUri());
        }

        return $next;
    }

    public function lastUri(): string
    {
        $last = $this->requests[count($this->requests) - 1] ?? null;

        if ($last === null) {
            throw new RuntimeException('No request was sent.');
        }

        return (string) $last->getUri();
    }

    /** @return list<string> */
    public function uris(): array
    {
        return array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests);
    }

    /**
     * Core's HttpClient wired to this fake.
     */
    public function asHttpClient(): HttpClient
    {
        return new HttpClient($this, new HttpFactory);
    }
}
