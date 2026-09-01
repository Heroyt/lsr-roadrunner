<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Lifecycle;

interface WorkerLifecycleHookInterface
{
    public function afterIteration(): void;

    public function workerStopped(): void;
}
