<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Lifecycle;

use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;

interface TaskDispatchLifecycleHookInterface
{
    /**
     * @param non-empty-list<PreparedTaskInterface> $tasks
     */
    public function begin(string $queue, array $tasks): TaskDispatchLifecycleScopeInterface;
}
