<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

interface TaskSerializerInterface
{

    /**
     * @param  TaskPayloadInterface  $data
     * @return non-empty-string|null
     */
    public function serialize(TaskPayloadInterface $data) : ?string;

    /**
     * @param  non-empty-string  $data
     * @return TaskPayloadInterface|null
     */
    public function unserialize(string $data) : ?TaskPayloadInterface;

}