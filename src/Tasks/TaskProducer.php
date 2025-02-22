<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

use Lsr\Roadrunner\Tasks\Serializers\TaskSerializerInterface;
use Spiral\RoadRunner\Jobs\Exception\JobsException;
use Spiral\RoadRunner\Jobs\OptionsInterface;
use Spiral\RoadRunner\Jobs\Queue;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;

class TaskProducer
{
    /** @var PreparedTaskInterface[] */
    private array $planned = [];

    public function __construct(
      private readonly Queue                   $queue,
      private readonly TaskSerializerInterface $serializer,
    ) {}

    /**
     * @param  class-string<TaskDispatcherInterface>  $dispatcher
     * @param  TaskPayloadInterface|null  $payload
     * @param  OptionsInterface|null  $options
     * @return void
     * @throws JobsException
     */
    public function push(string $dispatcher, ?TaskPayloadInterface $payload, ?OptionsInterface $options = null) : void {
        $this->queue->push(
          $dispatcher::getDiName(),
          $payload !== null ? ($this->serializer->serialize($payload) ?? '') : '',
          $options,
        );
    }

    /**
     * @param  class-string<TaskDispatcherInterface>  $dispatcher
     * @param  TaskPayloadInterface|null  $payload
     * @param  OptionsInterface|null  $options
     * @return PreparedTaskInterface
     */
    public function plan(
      string                $dispatcher,
      ?TaskPayloadInterface $payload,
      ?OptionsInterface     $options = null
    ) : PreparedTaskInterface {
        $task = $this->queue->create(
          $dispatcher::getDiName(),
          $payload !== null ? ($this->serializer->serialize($payload) ?? '') : '',
          $options,
        );
        $this->planned[] = $task;
        return $task;
    }

    /**
     * @return void
     * @throws JobsException
     */
    public function dispatch() : void {
        $this->queue->dispatchMany(...$this->planned);
        $this->planned = [];
    }
}