<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Workers;

use Lsr\Core\App;
use Lsr\Logging\Logger;
use Lsr\Orm\ModelRepository;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleScopeInterface;
use Lsr\Roadrunner\Lifecycle\WorkerLifecycleHookInterface;
use Lsr\Roadrunner\Tasks\Serializers\TaskSerializerInterface;
use Lsr\Roadrunner\Tasks\TaskDispatcherInterface;
use RuntimeException;
use Spiral\RoadRunner\Jobs\Consumer;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Throwable;
use Tracy\Debugger;
use Tracy\Helpers;
use Tracy\ILogger;

class JobsWorker implements Worker
{
    public App $app {
        get {
            if (!isset($this->app)) {
                $this->app = App::getInstance();
            }
            return $this->app;
        }
        set(App $value) => $this->app = $value;
    }

    private Logger $logger {
        get {
            if (!isset($this->logger)) {
                $this->logger = new Logger(LOG_DIR, 'worker-jobs');
            }
            return $this->logger;
        }
        set(Logger $value) => $this->logger = $value;
    }
    private ?TaskLifecycleHookInterface $taskLifecycleHook = null;
    private ?WorkerLifecycleHookInterface $workerLifecycleHook = null;

    public function __construct(
        protected readonly TaskSerializerInterface $serializer,
    ) {
    }

    public function setTaskLifecycleHook(TaskLifecycleHookInterface $hook): static
    {
        $this->taskLifecycleHook = $hook;
        return $this;
    }

    public function setWorkerLifecycleHook(WorkerLifecycleHookInterface $hook): static
    {
        $this->workerLifecycleHook = $hook;
        return $this;
    }

    public function run(): void
    {
        $consumer = new Consumer();

        try {
            while ($task = $consumer->waitTask()) {
                try {
                    $this->handleTask($task);
                } finally {
                    $this->afterIteration();
                }
            }
        } finally {
            $this->workerStopped();
        }
    }

    public function handleTask(ReceivedTaskInterface $task): void
    {
        $scope = $this->beginLifecycle($task);

        // Clear static cache
        ModelRepository::clearInstances();

        try {
            $name = $task->getName();

            $dispatcher = $this->app::getService($name);
            if (!($dispatcher instanceof TaskDispatcherInterface)) {
                $task->nack('Cannot find dispatcher for task "' . $name . '"');
                throw new RuntimeException('Cannot find dispatcher for task "' . $name . '"');
            }

            // Parse payload
            $rawPayload = $task->getPayload();
            $payload = $rawPayload !== '' ? $this->serializer->unserialize($rawPayload) : null;

            $dispatcher->process($task, $payload);

            if (!$task->isCompleted()) {
                $task->ack();
            }
        } catch (Throwable $e) {
            $this->recordLifecycleException($scope, $e);

            try {
                if (!$task->isCompleted()) {
                    $task->nack($e);
                }
            } catch (Throwable $nackException) {
                $this->recordLifecycleException($scope, $nackException);
                $this->handleError($nackException);
            }

            $this->handleError($e);
        } finally {
            try {
                $this->app->translations->updateTranslations();
            } catch (Throwable $translationException) {
                $this->recordLifecycleException($scope, $translationException);
                $this->handleError($translationException);
            }

            try {
                $scope?->complete();
            } catch (Throwable) {
                // Lifecycle hooks must never affect task handling.
            }
        }
    }

    private function beginLifecycle(ReceivedTaskInterface $task): ?TaskLifecycleScopeInterface
    {
        try {
            return $this->taskLifecycleHook?->begin($task);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordLifecycleException(
        ?TaskLifecycleScopeInterface $scope,
        Throwable $exception
    ): void {
        try {
            $scope?->recordException($exception);
        } catch (Throwable) {
            // Lifecycle hooks must never affect task handling.
        }
    }

    private function afterIteration(): void
    {
        try {
            $this->workerLifecycleHook?->afterIteration();
        } catch (Throwable) {
            // Lifecycle hooks must never affect worker execution.
        }
    }

    private function workerStopped(): void
    {
        try {
            $this->workerLifecycleHook?->workerStopped();
        } catch (Throwable) {
            // Lifecycle hooks must never affect worker execution.
        }
    }

    public function handleError(Throwable $error): void
    {
        $this->logger->exception($error);
        Helpers::improveException($error);
        Debugger::log($error, ILogger::EXCEPTION);
    }
}
