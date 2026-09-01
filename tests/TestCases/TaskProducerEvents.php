<?php

declare(strict_types=1);

namespace TestCases;

final class TaskProducerEvents
{
    /** @var list<string> */
    public array $events = [];
    public ?string $queue = null;

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}
