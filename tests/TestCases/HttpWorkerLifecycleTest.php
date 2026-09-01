<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Core\App;
use Lsr\Core\Translations;
use Lsr\Interfaces\RequestInterface;
use Lsr\Interfaces\SessionInterface;
use Lsr\Logging\Logger;
use Lsr\Roadrunner\Workers\HttpWorker;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7Worker;

final class HttpWorkerLifecycleTest extends TestCase
{
    public function testCleanupFailureDoesNotSendSecondResponse(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $session = $this->createStub(SessionInterface::class);
        $session->method('isInitialized')->willReturn(true);
        $session->method('getCookieHeader')->willReturn('session=test');
        $session->method('close')->willThrowException(new RuntimeException('close failed'));

        $translations = $this->createStub(Translations::class);
        $translations->method('getLang')->willReturn('en');

        $app = $this->createStub(App::class);
        $app->method('getRequest')->willReturn($request);
        $app->method('run')->willReturn(new Response());
        (new ReflectionProperty(App::class, 'session'))->setValue($app, $session);
        (new ReflectionProperty(App::class, 'translations'))->setValue($app, $translations);

        $psr7 = $this->createMock(PSR7Worker::class);
        $psr7->expects(self::once())->method('respond');

        $logger = $this->createStub(Logger::class);
        $worker = (new ReflectionClass(HttpWorker::class))->newInstanceWithoutConstructor();
        $worker->app = $app;
        (new ReflectionProperty(HttpWorker::class, 'psr7'))->setValue($worker, $psr7);
        (new ReflectionProperty(HttpWorker::class, 'logger'))->setValue($worker, $logger);

        $worker->handleRequest($request);
    }
}
