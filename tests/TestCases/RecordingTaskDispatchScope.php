<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleScopeInterface;
use RuntimeException;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Throwable;

final class RecordingTaskDispatchScope implements TaskDispatchLifecycleScopeInterface
{
    /** @var non-empty-list<PreparedTaskInterface>|null */
    private ?array $tasks = null;
    public ?Throwable $exception = null;

    public function __construct(private readonly TaskProducerEvents $events)
    {
    }

    /** @param non-empty-list<PreparedTaskInterface> $tasks */
    public function setTasks(array $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function tasks(): array
    {
        if ($this->tasks === null) {
            throw new RuntimeException('Tasks were not initialized.');
        }
        return $this->tasks;
    }

    public function recordException(Throwable $exception): void
    {
        $this->exception = $exception;
        $this->events->record('hook.exception');
    }

    public function complete(): void
    {
        $this->events->record('hook.complete');
    }
}
