<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Workers;

use Lsr\Core\App;

interface Worker
{

    public App $app {
        get;
        set;
    }

    public function run() : void;

    public function handleError(\Throwable $error) : void;

}