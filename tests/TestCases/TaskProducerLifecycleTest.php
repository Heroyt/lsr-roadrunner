<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Roadrunner\Tasks\Serializers\TaskSerializerInterface;
use Lsr\Roadrunner\Tasks\TaskProducer;
use PHPUnit\Framework\TestCase;
use RoadRunner\Jobs\DTO\V1\HeaderValue;
use RoadRunner\Jobs\DTO\V1\PushRequest;
use RuntimeException;
use Spiral\Goridge\RPC\CodecInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Jobs\Exception\JobsException;
use Spiral\RoadRunner\Jobs\Queue;
use Traversable;

final class TaskProducerLifecycleTest extends TestCase
{
    public function testHookCanPropagateHeadersBeforeDispatch(): void
    {
        $events = new TaskProducerEvents();
        $rpc = $this->createMock(RPCInterface::class);
        $rpc->expects(self::once())
            ->method('withCodec')
            ->with(self::isInstanceOf(CodecInterface::class))
            ->willReturnSelf();
        $rpc->expects(self::once())
            ->method('call')
            ->willReturnCallback(
                static function (string $method, mixed $payload) use ($events): null {
                    self::assertSame('jobs.Push', $method);
                    self::assertInstanceOf(PushRequest::class, $payload);
                    $job = $payload->getJob();
                    self::assertNotNull($job);
                    $header = $job->getHeaders()['traceparent'];
                    self::assertInstanceOf(HeaderValue::class, $header);
                    $values = $header->getValue();
                    self::assertInstanceOf(Traversable::class, $values);
                    self::assertSame(['00-test-trace'], iterator_to_array($values));
                    $events->record('queue.dispatch');
                    return null;
                },
            );
        $producer = new TaskProducer(
            new Queue('test', $rpc),
            $this->createStub(TaskSerializerInterface::class),
        );
        $scope = new RecordingTaskDispatchScope($events);
        $producer->setLifecycleHook(new RecordingTaskDispatchHook($events, $scope));

        $producer->push(LifecycleTaskDispatcher::class, null);

        self::assertSame(['hook.begin', 'queue.dispatch', 'hook.complete'], $events->events);
        self::assertSame('test', $events->queue);
        self::assertNull($scope->exception);
    }

    public function testDispatchFailureIsRecordedAndPreserved(): void
    {
        $events = new TaskProducerEvents();
        $rpcFailure = new RuntimeException('RPC failed');
        $rpc = $this->createMock(RPCInterface::class);
        $rpc->expects(self::once())->method('withCodec')->willReturnSelf();
        $rpc->expects(self::once())->method('call')->willThrowException($rpcFailure);
        $producer = new TaskProducer(
            new Queue('test', $rpc),
            $this->createStub(TaskSerializerInterface::class),
        );
        $scope = new RecordingTaskDispatchScope($events);
        $producer->setLifecycleHook(new RecordingTaskDispatchHook($events, $scope));

        try {
            $producer->push(LifecycleTaskDispatcher::class, null);
            self::fail('The queue exception was not rethrown.');
        } catch (JobsException $actual) {
            self::assertSame($rpcFailure, $actual->getPrevious());
        }

        self::assertInstanceOf(JobsException::class, $scope->exception);
        self::assertSame(['hook.begin', 'hook.exception', 'hook.complete'], $events->events);
    }
}
