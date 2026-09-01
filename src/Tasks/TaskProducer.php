<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleScopeInterface;
use Lsr\Roadrunner\Tasks\Serializers\TaskSerializerInterface;
use Spiral\RoadRunner\Jobs\Exception\JobsException;
use Spiral\RoadRunner\Jobs\OptionsInterface;
use Spiral\RoadRunner\Jobs\Queue;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Throwable;

class TaskProducer
{
    /** @var list<PreparedTaskInterface> */
    private array $planned = [];
    private ?TaskDispatchLifecycleHookInterface $lifecycleHook = null;

    public function __construct(
        private readonly Queue $queue,
        private readonly TaskSerializerInterface $serializer,
    ) {
    }

    public function setLifecycleHook(TaskDispatchLifecycleHookInterface $hook): static
    {
        $this->lifecycleHook = $hook;
        return $this;
    }

    /**
     * @param  class-string<TaskDispatcherInterface>  $dispatcher
     * @param  TaskPayloadInterface|null  $payload
     * @param  OptionsInterface|null  $options
     * @return void
     * @throws JobsException
     */
    public function push(string $dispatcher, ?TaskPayloadInterface $payload, ?OptionsInterface $options = null): void
    {
        $task = $this->queue->create(
            $dispatcher::getDiName(),
            $payload !== null ? ($this->serializer->serialize($payload) ?? '') : '',
            $options,
        );

        $scope = $this->beginLifecycle([$task]);
        $task = $scope?->tasks()[0] ?? $task;

        try {
            $this->queue->dispatch($task);
        } catch (Throwable $exception) {
            $this->recordLifecycleException($scope, $exception);
            throw $exception;
        } finally {
            $this->completeLifecycle($scope);
        }
    }

    /**
     * @param  class-string<TaskDispatcherInterface>  $dispatcher
     * @param  TaskPayloadInterface|null  $payload
     * @param  OptionsInterface|null  $options
     * @return PreparedTaskInterface
     */
    public function plan(
        string $dispatcher,
        ?TaskPayloadInterface $payload,
        ?OptionsInterface $options = null
    ): PreparedTaskInterface {
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
    public function dispatch(): void
    {
        if ($this->planned === []) {
            $this->queue->dispatchMany();
            return;
        }

        $scope = $this->beginLifecycle($this->planned);
        $tasks = $scope?->tasks() ?? $this->planned;

        try {
            $this->queue->dispatchMany(...$tasks);
            $this->planned = [];
        } catch (Throwable $exception) {
            $this->recordLifecycleException($scope, $exception);
            throw $exception;
        } finally {
            $this->completeLifecycle($scope);
        }
    }

    /**
     * @param non-empty-list<PreparedTaskInterface> $tasks
     */
    private function beginLifecycle(array $tasks): ?TaskDispatchLifecycleScopeInterface
    {
        try {
            return $this->lifecycleHook?->begin($this->queue->getName(), $tasks);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordLifecycleException(
        ?TaskDispatchLifecycleScopeInterface $scope,
        Throwable $exception
    ): void {
        try {
            $scope?->recordException($exception);
        } catch (Throwable) {
            // Lifecycle hooks must never affect task dispatch.
        }
    }

    private function completeLifecycle(?TaskDispatchLifecycleScopeInterface $scope): void
    {
        try {
            $scope?->complete();
        } catch (Throwable) {
            // Lifecycle hooks must never affect task dispatch.
        }
    }
}
