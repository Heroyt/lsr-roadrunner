<?php
declare(strict_types=1);

namespace Lsr\Roadrunner;

use Lsr\Roadrunner\Workers\Worker;
use RuntimeException;
use Spiral\RoadRunner\Environment;

class Server
{

    /**
     * @param  array<non-empty-string,Worker>  $workers
     */
    public function __construct(
      private readonly array $workers = [],
    ) {}

    public function run() : void {
        $env = Environment::fromGlobals();

        $mode = $env->getMode();
        if (isset($this->workers[$mode])) {
            $this->workers[$mode]->run();
            return;
        }

        throw new RuntimeException(
          'Cannot find worker for mode "'.$mode.'". Available workers: '.implode(', ', array_keys($this->workers))
        );
    }

}