<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Core\App;
use Lsr\Core\Requests\Request;
use Lsr\Roadrunner\DI\RoadrunnerExtension;
use Lsr\Roadrunner\ErrorHandlers\HttpErrorHandler;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\Definitions\Statement;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7Worker;
use stdClass;
use Tracy\Debugger;
use Tracy\ILogger;

final class LoggingReplacementHttpWorker extends HttpWorker
{
    public function __construct(
        public readonly HttpErrorHandler $error500Handler,
        public readonly HttpErrorHandler $error403Handler,
        public readonly HttpErrorHandler $error404Handler,
        public readonly HttpErrorHandler $error405Handler,
        public readonly stdClass $dependency,
    ) {
        // Avoid opening RoadRunner transport; the public error path is exercised below.
    }
}

final class RoadrunnerLoggingExtensionTest extends TestCase
{
    private ILogger $previousTracyLogger;

    protected function setUp(): void {
        $this->previousTracyLogger = Debugger::getLogger();
        Debugger::setLogger($this->createStub(ILogger::class));
    }

    protected function tearDown(): void {
        Debugger::setLogger($this->previousTracyLogger);
    }

    public function test_common_logger_is_shared_without_changing_autowiring_or_replacement_constructors(): void {
        $container = $this->compile(['logger' => '@shared']);
        $shared = $container->getService('shared');
        self::assertInstanceOf(RecordingLogger::class, $shared);
        self::assertSame($shared, $container->getService('rr.logger.http'));
        self::assertSame($shared, $container->getService('rr.logger.jobs'));
        $http = $container->getService('rr.worker.http');
        self::assertInstanceOf(LoggingReplacementHttpWorker::class, $http);
        self::assertSame($container->getService('dependency'), $http->dependency);
        $jobs = $container->getService('rr.worker.jobs');
        self::assertInstanceOf(JobsWorker::class, $jobs);

        $this->reportHttpError($http, new RuntimeException('HTTP shared logger'));
        $jobs->handleError(new RuntimeException('Jobs shared logger'));

        self::assertSame(['error', 'debug', 'error', 'debug'], array_column($shared->records, 'level'));
        self::assertStringContainsString('HTTP shared logger', $shared->records[0]['message']);
        self::assertStringContainsString('Jobs shared logger', $shared->records[2]['message']);
        $global = $container->getService('logger');
        self::assertInstanceOf(RecordingLogger::class, $global);
        self::assertSame($global, $container->getByType(LoggerInterface::class));
        self::assertSame([], $global->records);
    }

    public function test_purpose_override_wins_and_null_inherits_common_logger(): void {
        $container = $this->compile([
            'logger' => '@shared',
            'loggers' => ['http' => '@httpLogger', 'jobs' => null],
        ]);
        $httpLogger = $container->getService('httpLogger');
        $common = $container->getService('shared');
        self::assertInstanceOf(RecordingLogger::class, $httpLogger);
        self::assertInstanceOf(RecordingLogger::class, $common);
        self::assertSame($httpLogger, $container->getService('rr.logger.http'));
        self::assertSame($common, $container->getService('rr.logger.jobs'));
        $http = $container->getService('rr.worker.http');
        self::assertInstanceOf(HttpWorker::class, $http);
        $jobs = $container->getService('rr.worker.jobs');
        self::assertInstanceOf(JobsWorker::class, $jobs);

        $this->reportHttpError($http, new RuntimeException('HTTP purpose logger'));
        $jobs->handleError(new RuntimeException('Jobs common logger'));

        self::assertSame(['error', 'debug'], array_column($httpLogger->records, 'level'));
        self::assertSame(['error', 'debug'], array_column($common->records, 'level'));
        self::assertStringContainsString('HTTP purpose logger', $httpLogger->records[0]['message']);
        self::assertStringContainsString('Jobs common logger', $common->records[0]['message']);
    }

    public function test_unconfigured_workers_keep_separate_file_outputs_instead_of_global_logger(): void {
        $container = $this->compile([]);
        $paths = [LOG_DIR . 'worker-' . date('Y-m-d') . '.log', LOG_DIR . 'worker-jobs-' . date('Y-m-d') . '.log'];
        $originals = [];
        foreach ($paths as $path) {
            $originals[$path] = is_file($path) ? file_get_contents($path) : false;
        }
        $httpMessage = 'HTTP fallback ' . uniqid('', true);
        $jobsMessage = 'Jobs fallback ' . uniqid('', true);

        try {
            $http = $container->getService('rr.worker.http');
            self::assertInstanceOf(HttpWorker::class, $http);
            $jobs = $container->getService('rr.worker.jobs');
            self::assertInstanceOf(JobsWorker::class, $jobs);
            $this->reportHttpError($http, new RuntimeException($httpMessage));
            $jobs->handleError(new RuntimeException($jobsMessage));

            $httpLog = file_get_contents($paths[0]);
            $jobsLog = file_get_contents($paths[1]);
            self::assertIsString($httpLog);
            self::assertIsString($jobsLog);
            self::assertStringContainsString($httpMessage, $httpLog);
            self::assertStringNotContainsString($jobsMessage, $httpLog);
            self::assertStringContainsString($jobsMessage, $jobsLog);
            self::assertStringNotContainsString($httpMessage, $jobsLog);
            $global = $container->getService('logger');
            self::assertInstanceOf(RecordingLogger::class, $global);
            self::assertSame([], $global->records);
        } finally {
            foreach ($originals as $path => $contents) {
                if ($contents !== false) {
                    file_put_contents($path, $contents);
                } elseif (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function compile(array $config): Container {
        $class = 'RoadrunnerLoggerContainer' . str_replace('.', '', uniqid('', true));
        $compiler = new Compiler();
        $compiler->setClassName($class);
        $compiler->addExtension('rr', new RoadrunnerExtension());
        $compiler->addConfig([
            'rr' => $config,
            'services' => [
                'logger' => RecordingLogger::class,
                'shared' => ['create' => RecordingLogger::class, 'autowired' => false],
                'httpLogger' => ['create' => RecordingLogger::class, 'autowired' => false],
                'dependency' => stdClass::class,
                'rr.worker.http' => new Statement(LoggingReplacementHttpWorker::class, [
                    '@rr.httpErrorHandler.500',
                    '@rr.httpErrorHandler.403',
                    '@rr.httpErrorHandler.404',
                    '@rr.httpErrorHandler.405',
                ]),
            ],
        ]);
        eval($compiler->compile());
        $container = new $class();
        self::assertInstanceOf(Container::class, $container);
        return $container;
    }

    private function reportHttpError(HttpWorker $worker, RuntimeException $error): void {
        $request = $this->createStub(Request::class);
        $app = $this->createStub(App::class);
        $app->method('getRequest')->willReturn($request);
        $app->method('isProduction')->willReturn(true);
        $worker->app = $app;
        $handler = $this->createStub(HttpErrorHandler::class);
        $handler->method('showError')->willReturn(new Response(500));
        (new ReflectionProperty(HttpWorker::class, 'error500Handler'))->setValue($worker, $handler);
        $psr7 = $this->createMock(PSR7Worker::class);
        $psr7->expects(self::once())->method('respond');
        (new ReflectionProperty(HttpWorker::class, 'psr7'))->setValue($worker, $psr7);

        $worker->handleError($error);
    }
}
