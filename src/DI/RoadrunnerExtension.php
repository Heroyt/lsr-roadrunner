<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\DI;

use Lsr\Roadrunner\ErrorHandlers\Http403ErrorHandler;
use Lsr\Roadrunner\ErrorHandlers\Http404ErrorHandler;
use Lsr\Roadrunner\ErrorHandlers\Http500ErrorHandler;
use Lsr\Roadrunner\Server;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use Lsr\Roadrunner\Workers\Worker;
use Nette;
use Nette\DI\CompilerExtension;
use Spiral\RoadRunner\Environment;

class RoadrunnerExtension extends CompilerExtension
{

    public function getConfigSchema() : Nette\Schema\Schema {
        return Nette\Schema\Expect::structure(
          [
            'workers' => Nette\Schema\Expect::arrayOf(
              Nette\Schema\Expect::type(Worker::class),
              Nette\Schema\Expect::string(),
            )->default(
              [
                Environment\Mode::MODE_HTTP => '@'.$this->prefix('worker.http'),
                Environment\Mode::MODE_JOBS => '@'.$this->prefix('worker.jobs'),
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
                ->setFactory(
                  JobsWorker::class,
                  [
                  ]
                )
                ->setTags(['lsr', 'roadrunner', 'jobs']);


        $builder->addDefinition($this->prefix('server'))
                ->setType(Server::class)
                ->setFactory(
                  Server::class,
                  [
                    $this->config->workers,
                  ]
                )
                ->setTags(['lsr', 'roadrunner']);
    }

}