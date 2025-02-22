<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Workers;

use Lsr\Core\App;
use Lsr\Logging\Logger;
use Lsr\Orm\ModelRepository;
use Lsr\Roadrunner\Tasks\Serializers\TaskSerializerInterface;
use Lsr\Roadrunner\Tasks\TaskDispatcherInterface;
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
    /** @phpstan-ignore property.onlyRead */
    private Logger $logger {
        get {
            if (!isset($this->logger)) {
                $this->logger = new Logger(LOG_DIR, 'worker-jobs');
            }
            return $this->logger;
        }
        set(Logger $value) => $this->logger = $value;
    }

    public function __construct(
      protected readonly TaskSerializerInterface $serializer,
    ) {}

    public function run() : void {
        $consumer = new Consumer();
        while ($task = $consumer->waitTask()) {
            $this->handleTask($task);
        }
    }

    public function handleTask(ReceivedTaskInterface $task) : void {
        // Clear static cache
        ModelRepository::clearInstances();

        try {
            $name = $task->getName();

            $dispatcher = $this->app::getService($name);
            if (!($dispatcher instanceof TaskDispatcherInterface)) {
                $task->nack('Cannot find dispatcher for task "'.$name.'"');
                throw new \RuntimeException('Cannot find dispatcher for task "'.$name.'"');
            }

            // Parse payload
            $rawPayload = $task->getPayload();
            $payload = $rawPayload !== '' ? $this->serializer->unserialize($rawPayload) : null;

            $dispatcher->process($task, $payload);

            if (!$task->isCompleted()) {
                $task->ack();
            }
        } catch (Throwable $e) {
            $task->nack($e);
            $this->handleError($e);
        }

        $this->app->translations->updateTranslations();
    }

    public function handleError(\Throwable $error) : void {
        $this->logger->exception($error);
        Helpers::improveException($error);
        Debugger::log($error, ILogger::EXCEPTION);
    }
}