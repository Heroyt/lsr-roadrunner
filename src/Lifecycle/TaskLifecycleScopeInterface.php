<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Lifecycle;

use Throwable;

interface TaskLifecycleScopeInterface
{
    public function recordException(Throwable $exception): void;

    public function complete(): void;
}
