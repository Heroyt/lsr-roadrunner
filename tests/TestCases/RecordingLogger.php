<?php

declare(strict_types=1);

namespace TestCases;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];
    public ?Throwable $failure = null;

    /**
     * @param string|Stringable $message
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, mixed $message, array $context = []): void {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
