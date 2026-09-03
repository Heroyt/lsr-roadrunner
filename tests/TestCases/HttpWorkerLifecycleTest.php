<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Core\App;
use Lsr\Core\Translations;
use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RequestLifecycleScopeInterface;
use Lsr\Interfaces\RequestInterface;
use Lsr\Interfaces\SessionInterface;
use Lsr\Logging\Logger;
use Lsr\Roadrunner\Workers\HttpWorker;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Throwable;

final class HttpRequestLifecycleState
{
    public int $begun = 0;
    public int $completed = 0;
    public bool $active = false;
    /** @var list<int|null> */
    public array $responseStatuses = [];
}


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

    public function testSequentialRequestsHaveIsolatedLifecycleScopes(): void
    {
        if (
            !interface_exists(RequestLifecycleHookInterface::class)
            || !interface_exists(RequestLifecycleScopeInterface::class)
        ) {
            self::markTestSkipped('The compatible lsr/core request lifecycle is not installed.');
        }

        $request = $this->createStub(RequestInterface::class);
        $session = $this->createStub(SessionInterface::class);
        $session->method('isInitialized')->willReturn(true);
        $session->method('getCookieHeader')->willReturn('session=test');

        $translations = $this->createStub(Translations::class);
        $translations->method('getLang')->willReturn('en');

        $app = $this->createStub(App::class);
        $app->method('getRequest')->willReturn($request);
        $app->method('run')->willReturn(new Response(204));
        (new ReflectionProperty(App::class, 'session'))->setValue($app, $session);
        (new ReflectionProperty(App::class, 'translations'))->setValue($app, $translations);

        $psr7 = $this->createMock(PSR7Worker::class);
        $psr7->expects(self::exactly(2))->method('respond');

        $worker = (new ReflectionClass(HttpWorker::class))->newInstanceWithoutConstructor();
        $worker->app = $app;
        (new ReflectionProperty(HttpWorker::class, 'psr7'))->setValue($worker, $psr7);

        $state = new HttpRequestLifecycleState();
        $hook = new class($state) implements RequestLifecycleHookInterface {
            public function __construct(private readonly HttpRequestLifecycleState $state)
            {
            }

            public function begin(ServerRequestInterface $request): RequestLifecycleScopeInterface
            {
                if ($this->state->active) {
                    throw new RuntimeException('Previous request lifecycle is still active.');
                }
                $this->state->active = true;
                $this->state->begun++;

                return new class($this->state) implements RequestLifecycleScopeInterface {
                    private bool $completed = false;

                    public function __construct(private readonly HttpRequestLifecycleState $state)
                    {
                    }

                    public function recordException(Throwable $exception): void
                    {
                    }

                    public function complete(?ResponseInterface $response = null): void
                    {
                        if ($this->completed) {
                            return;
                        }
                        $this->completed = true;
                        $this->state->active = false;
                        $this->state->completed++;
                        $this->state->responseStatuses[] = $response?->getStatusCode();
                    }
                };
            }
        };
        $worker->setRequestLifecycleHook($hook);
        $worker->handleRequest($request);
        $worker->handleRequest($request);

        self::assertSame(2, $state->begun);
        self::assertSame(2, $state->completed);
        self::assertFalse($state->active);
        self::assertSame([204, 204], $state->responseStatuses);
    }
}
