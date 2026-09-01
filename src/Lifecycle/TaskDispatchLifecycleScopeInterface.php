<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Lifecycle;

use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Throwable;

interface TaskDispatchLifecycleScopeInterface
{
    /**
     * @return non-empty-list<PreparedTaskInterface>
     */
    public function tasks(): array;

    public function recordException(Throwable $exception): void;

    public function complete(): void;
}
