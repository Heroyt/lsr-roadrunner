<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\DI;

use Lsr\Roadrunner\ErrorHandlers\Http403ErrorHandler;
use Lsr\Roadrunner\ErrorHandlers\Http404ErrorHandler;
use Lsr\Roadrunner\ErrorHandlers\Http500ErrorHandler;
use Lsr\Roadrunner\Server;
use Lsr\Roadrunner\Tasks\Serializers\PhpTaskSerializer;
use Lsr\Roadrunner\Tasks\Serializers\TaskSerializerInterface;
use Lsr\Roadrunner\Tasks\TaskProducer;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use Lsr\Roadrunner\Workers\Worker;
use Nette;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Spiral\Goridge\RPC\MultiRPC;
use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\Jobs\Jobs;
use Spiral\RoadRunner\Jobs\Queue;
use stdClass;

/**
 * @property-read object{
 *     workers: array<string,Worker|string>,
 *     rpc: object{host: string, port: int},
 *     jobs: object{queue: string, serializer: TaskSerializerInterface|string}
 *  }&stdClass $config
 */
class RoadrunnerExtension extends CompilerExtension
{

    public function getConfigSchema() : Nette\Schema\Schema {
        return Expect::structure(
          [
            'workers' => Expect::arrayOf(
              Expect::anyOf(Expect::type(Worker::class), Expect::string()),
              Expect::string(),
            )->default(
              [
                Environment\Mode::MODE_HTTP => '@'.$this->prefix('worker.http'),
                Environment\Mode::MODE_JOBS => '@'.$this->prefix('worker.jobs'),
              ]
            ),
            'rpc'     => Expect::structure(
              [
                'host' => Expect::string('tcp://localhost'),
                'port' => Expect::int(6001),
              ]
            ),
            'jobs'    => Expect::structure(
              [
                'queue'      => Expect::string('tasks'),
                'serializer' => Expect::type(TaskSerializerInterface::class.'|string')->default(
                  '@'.$this->prefix('tasks.serializer')
                ),
              ]
            ),
          ]
        );
    }

    public function loadConfiguration() : void {
        $builder = $this->getContainerBuilder();

        // Http error handlers
        $builder->addDefinition($this->prefix('httpErrorHandler.500'))
                ->setType(Http500ErrorHandler::class)
                ->setTags(['lsr', 'roadrunner', 'http']);
        $builder->addDefinition($this->prefix('httpErrorHandler.404'))
                ->setType(Http404ErrorHandler::class)
                ->setTags(['lsr', 'roadrunner', 'http']);
        $builder->addDefinition($this->prefix('httpErrorHandler.403'))
                ->setType(Http403ErrorHandler::class)
                ->setTags(['lsr', 'roadrunner', 'http']);

        // Workers
        $builder->addDefinition($this->prefix('worker.http'))
                ->setType(HttpWorker::class)
                ->setFactory(
                  HttpWorker::class,
                  [
                    '@'.$this->prefix('httpErrorHandler.500'),
                    '@'.$this->prefix('httpErrorHandler.404'),
                    '@'.$this->prefix('httpErrorHandler.403'),
                  ]
                )
                ->setTags(['lsr', 'roadrunner', 'http']);
        $builder->addDefinition($this->prefix('worker.jobs'))
                ->setType(JobsWorker::class)
                ->setTags(['lsr', 'roadrunner', 'jobs']);


        // Main server
        $builder->addDefinition($this->prefix('server'))
                ->setType(Server::class)
                ->setFactory(
                  Server::class,
                  [
                    $this->config->workers,
                  ]
                )
                ->setTags(['lsr', 'roadrunner']);

        // RPC
        $builder->addDefinition($this->prefix('rpc'))
                ->setType(RPC::class)
                ->setFactory(
                  [RPC::class, 'create']
                  [$this->config->rpc->host.':'.$this->config->rpc->port]
                )
                ->setTags(['lsr', 'roadrunner', 'rpc']);
        $builder->addDefinition($this->prefix('asyncRpc'))
                ->setType(MultiRPC::class)
                ->setFactory(
                  [MultiRPC::class, 'create']
                  [$this->config->rpc->host.':'.$this->config->rpc->port]
                )
                ->setTags(['lsr', 'roadrunner']);

        // Jobs
        $builder->addDefinition($this->prefix('jobs'))
                ->setType(Jobs::class)
                ->setFactory(
                  Jobs::class,
                  [
                    '@'.$this->prefix('rpc'),
                  ]
                )
                ->setTags(['lsr', 'roadrunner', 'jobs']);
        $builder->addDefinition($this->prefix('queue'))
                ->setType(Queue::class)
                ->setFactory(
                  ['@'.$this->prefix('jobs'), 'connect'],
                  [
                    $this->config->jobs->queue,
                  ]
                )
                ->setTags(['lsr', 'roadrunner', 'jobs']);
        $builder->addDefinition($this->prefix('tasks.serializer'))
                ->setType(TaskSerializerInterface::class)
                ->setFactory(PhpTaskSerializer::class)
                ->setTags(['lsr', 'roadrunner', 'jobs']);
        $builder->addDefinition($this->prefix('tasks.producer'))
                ->setType(TaskProducer::class)
                ->setFactory(
                  TaskProducer::class,
                  [
                    '@'.$this->prefix('queue'),
                    $this->config->jobs->serializer,
                  ]
                )
                ->setTags(['lsr', 'roadrunner', 'jobs']);
    }

}