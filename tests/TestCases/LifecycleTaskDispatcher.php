<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Roadrunner\Tasks\TaskDispatcherInterface;
use Lsr\Roadrunner\Tasks\TaskPayloadInterface;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;

final class LifecycleTaskDispatcher implements TaskDispatcherInterface
{
    public static function getDiName(): string
    {
        return 'lifecycle-test';
    }

    public function process(ReceivedTaskInterface $task, ?TaskPayloadInterface $payload = null): void
    {
    }
}
