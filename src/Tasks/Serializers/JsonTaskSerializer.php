<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Tasks;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

readonly class JsonTaskSerializer implements TaskSerializerInterface
{

    public function __construct(
      protected SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer,
    ) {}

    /**
     * @inheritDoc
     */
    public function serialize(TaskPayloadInterface $data) : ?string {
        try {
            $dto = new TaskPayloadDto();
            $dto->payloadClass = $data::class;
            $dto->data = $this->serializer->normalize($data);
            /** @phpstan-ignore return.type */
            return $this->serializer->serialize($dto, 'json');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function unserialize(string $data) : ?TaskPayloadInterface {
        try {
            $dto = $this->serializer->deserialize($data, TaskPayloadDto::class, 'json');
            $payload = $this->serializer->denormalize($dto->data, $dto->payloadClass);
            assert($payload instanceof TaskPayloadInterface);
            return $payload;
        } catch (Throwable $e) {
            return null;
        }
    }
}