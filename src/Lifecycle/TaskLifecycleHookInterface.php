<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Lifecycle;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;

interface TaskLifecycleHookInterface
{
    public function begin(ReceivedTaskInterface $task): TaskLifecycleScopeInterface;
}
