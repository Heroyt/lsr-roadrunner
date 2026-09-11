<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Core\App;
use Lsr\Core\Requests\Request;
use Lsr\Core\Translations;
use Lsr\Roadrunner\ErrorHandlers\HttpErrorHandler;
use Lsr\Roadrunner\Tasks\Serializers\PhpTaskSerializer;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Jobs\Task\ReceivedTask;
use Tracy\Debugger;
use Tracy\ILogger;

final class WorkerLoggingTest extends TestCase
{
    private ILogger $previousTracyLogger;

    protected function setUp(): void {
        $this->previousTracyLogger = Debugger::getLogger();
    }

    protected function tearDown(): void {
        Debugger::setLogger($this->previousTracyLogger);
    }

    public function test_http_error_reports_psr_records_and_sends_one_response(): void {
        $error = new RuntimeException('HTTP request failed', 17);
        $tracy = $this->createMock(ILogger::class);
        $tracy->expects(self::once())->method('log')->with($error, ILogger::EXCEPTION);
        Debugger::setLogger($tracy);

        $request = $this->createStub(Request::class);
        $app = $this->createStub(App::class);
        $app->method('getRequest')->willReturn($request);
        $app->method('isProduction')->willReturn(true);
        $handler = $this->createStub(HttpErrorHandler::class);
        $handler->method('showError')->willReturn(new Response(500));
        $psr7 = $this->createMock(PSR7Worker::class);
        $psr7->expects(self::once())->method('respond')->with(
            self::callback(static fn (ResponseInterface $response): bool => $response->getStatusCode() === 500),
        );

        $logger = new RecordingLogger();
        $worker = (new ReflectionClass(HttpWorker::class))->newInstanceWithoutConstructor();
        $worker->app = $app;
        $worker->setLogger($logger);
        (new ReflectionProperty(HttpWorker::class, 'error500Handler'))->setValue($worker, $handler);
        (new ReflectionProperty(HttpWorker::class, 'psr7'))->setValue($worker, $psr7);

        $worker->handleError($error);

        self::assertSame(['error', 'debug'], array_column($logger->records, 'level'));
        self::assertStringContainsString($error->getMessage(), $logger->records[0]['message']);
        self::assertStringContainsString((string) $error->getCode(), $logger->records[0]['message']);
        self::assertSame($error->getTraceAsString(), $logger->records[1]['message']);
        self::assertSame([[], []], array_column($logger->records, 'context'));
    }

    public function test_job_failure_is_nacked_and_reported_through_psr(): void {
        $error = new RuntimeException('Task metadata could not be read');
        $tracy = $this->createMock(ILogger::class);
        $tracy->expects(self::once())->method('log')->with($error, ILogger::EXCEPTION);
        Debugger::setLogger($tracy);
        $task = $this->createMock(ReceivedTask::class);
        $task->method('getName')->willThrowException($error);
        $task->method('isCompleted')->willReturn(false);
        $task->expects(self::once())->method('nack')->with($error);
        $task->expects(self::never())->method('ack');
        $app = $this->createStub(App::class);
        (new ReflectionProperty(App::class, 'translations'))->setValue(
            $app,
            $this->createStub(Translations::class),
        );
        $logger = new RecordingLogger();
        $worker = new JobsWorker(new PhpTaskSerializer());
        $worker->app = $app;
        $worker->setLogger($logger);

        $worker->handleTask($task);

        self::assertSame(['error', 'debug'], array_column($logger->records, 'level'));
        self::assertStringContainsString($error->getMessage(), $logger->records[0]['message']);
        self::assertSame($error->getTraceAsString(), $logger->records[1]['message']);
        self::assertSame([[], []], array_column($logger->records, 'context'));
    }

    public function test_logger_failure_propagates_before_tracy_reporting(): void {
        $failure = new RuntimeException('Log storage failed');
        $logger = new RecordingLogger();
        $logger->failure = $failure;
        $worker = new JobsWorker(new PhpTaskSerializer());
        $worker->setLogger($logger);
        $tracy = $this->createMock(ILogger::class);
        $tracy->expects(self::never())->method('log');
        Debugger::setLogger($tracy);

        try {
            $worker->handleError(new RuntimeException('Task failed'));
            self::fail('The synchronous logging failure was not propagated.');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }

        self::assertSame(['error'], array_column($logger->records, 'level'));
    }
}
