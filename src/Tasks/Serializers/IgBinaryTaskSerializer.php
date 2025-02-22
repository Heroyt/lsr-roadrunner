<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

class IgBinaryTaskSerializer implements TaskSerializerInterface
{

    public function serialize(TaskPayloadInterface $data) : ?string {
        assert(extension_loaded('igbinary'));
        /** @phpstan-ignore return.type */
        return igbinary_serialize($data);
    }

    public function unserialize(string $data) : ?TaskPayloadInterface {
        assert(extension_loaded('igbinary'));
        $data = igbinary_unserialize($data);
        if (!($data instanceof TaskPayloadInterface)) {
            return null;
        }
        return $data;
    }
}