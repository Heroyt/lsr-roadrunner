<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleScopeInterface;

final readonly class RecordingTaskDispatchHook implements TaskDispatchLifecycleHookInterface
{
    public function __construct(
        private TaskProducerEvents $events,
        private RecordingTaskDispatchScope $scope,
    ) {
    }

    public function begin(string $queue, array $tasks): TaskDispatchLifecycleScopeInterface
    {
        $this->events->queue = $queue;
        $this->events->record('hook.begin');
        $this->scope->setTasks([
            $tasks[0]->withHeader('traceparent', '00-test-trace'),
        ]);
        return $this->scope;
    }
}
