<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

final class TaskPayloadDto
{

    /** @var class-string */
    public string $payloadClass;

    public mixed $data;

}