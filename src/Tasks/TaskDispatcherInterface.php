<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;

interface TaskDispatcherInterface
{
    /**
     * @return non-empty-string
     */
    public static function getDiName() : string;

    public function process(ReceivedTaskInterface $task) : void;
}