<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Workable\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (array $r): string => $r['message'], $this->records);
    }

    /** @return list<mixed> */
    public function levels(): array
    {
        return array_map(static fn (array $r): mixed => $r['level'], $this->records);
    }
}
