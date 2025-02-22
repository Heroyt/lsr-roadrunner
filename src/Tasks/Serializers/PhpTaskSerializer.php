<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks\Serializers;

use Lsr\Roadrunner\Tasks\TaskPayloadInterface;

readonly class PhpTaskSerializer implements TaskSerializerInterface
{

    public function serialize(TaskPayloadInterface $data) : ?string {
        /** @phpstan-ignore return.type */
        return serialize($data);
    }

    public function unserialize(string $data) : ?TaskPayloadInterface {
        $data = unserialize($data, ['allowed_classes' => true]);
        if (!($data instanceof TaskPayloadInterface)) {
            return null;
        }
        return $data;
    }
}