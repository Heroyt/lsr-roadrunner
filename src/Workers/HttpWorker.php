<?php

declare(strict_types=1);

namespace Lsr\Roadrunner\Workers;

use LogicException;
use Lsr\Core\App;
use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RequestLifecycleScopeInterface;
use Lsr\Core\Requests\Exceptions\RouteNotFoundException;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\Exceptions\AccessDeniedException;
use Lsr\Core\Routing\Exceptions\MethodNotAllowedException;
use Lsr\Exceptions\DispatchBreakException;
use Lsr\Interfaces\RequestFactoryInterface;
use Lsr\Interfaces\RequestInterface;
use Lsr\Logging\Logger;
use Lsr\Orm\ModelRepository;
use Lsr\Roadrunner\ErrorHandlers\HttpErrorHandler;
use Lsr\Roadrunner\Lifecycle\WorkerLifecycleHookInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker as RrWorker;
use Throwable;
use Tracy\Debugger;
use Tracy\Helpers;
use Tracy\ILogger;

class HttpWorker implements Worker
{
    public App $app {
        get {
            if (!isset($this->app)) {
                $this->app = App::getInstance();
            }
            return $this->app;
        }
        set(App $value) => $this->app = $value;
    }

    private Logger $logger {
        get {
            if (!isset($this->logger)) {
                $this->logger = new Logger(LOG_DIR, 'worker');
            }
            return $this->logger;
        }
        set(Logger $value) => $this->logger = $value;
    }
    private RrWorker $worker;
    private PSR7Worker $psr7;
    private ?RequestLifecycleHookInterface $requestLifecycleHook = null;
    private ?WorkerLifecycleHookInterface $workerLifecycleHook = null;
    private ?\Psr\Http\Message\ResponseInterface $response = null;

    private RequestFactoryInterface $requestFactory {
        get {
            if (!isset($this->requestFactory)) {
                $service = $this->app::getServiceByType(RequestFactoryInterface::class);
                if ($service === null) {
                    throw new LogicException(
                        'RequestFactory service is not set. Please ensure it is registered in the application.'
                    );
                }
                $this->requestFactory = $service;
            }
            return $this->requestFactory;
        }
        set(RequestFactoryInterface $value) => $this->requestFactory = $value;
    }

    public function __construct(
        private readonly HttpErrorHandler $error500Handler,
        private readonly HttpErrorHandler $error403Handler,
        private readonly HttpErrorHandler $error404Handler,
        private readonly HttpErrorHandler $error405Handler,
    ) {
        $this->worker = RrWorker::create();

        $factory = new Psr17Factory();
        $this->psr7 = new PSR7Worker($this->worker, $factory, $factory, $factory);
    }

    public function setRequestLifecycleHook(RequestLifecycleHookInterface $hook): static
    {
        $this->requestLifecycleHook = $hook;
        return $this;
    }

    public function setWorkerLifecycleHook(WorkerLifecycleHookInterface $hook): static
    {
        $this->workerLifecycleHook = $hook;
        return $this;
    }

    public function run(): void
    {
        $request = null;

        try {
            while (true) {
                $this->response = null;
                $iterationStarted = false;
                if (isset($request)) {
                    unset($request);
                }

                try {
                    try {
                        $request = $this->psr7->waitRequest();
                        if ($request === null) {
                            break;
                        }
                        $iterationStarted = true;
                        $request = $this->requestFactory->fromPsrRequest($request);
                    } catch (Throwable $e) {
                        // Although the PSR-17 specification clearly states that there can be
                        // no exceptions when creating a request, however, some implementations
                        // may violate this rule. Therefore, it is recommended to process the
                        // incoming request for errors.
                        //
                        // Send "Bad Request" response.
                        $this->respond(new Response(400, body: $e->getMessage()));
                        continue;
                    }

                    if (!($request instanceof RequestInterface)) {
                        throw new LogicException(
                            'Roadrunner HTTP worker requires a RequestInterface instance from RequestFactory, '
                            . get_class($request) . ' given.'
                        );
                    }

                    $this->handleRequest($request);
                } catch (Throwable $e) {
                    $this->handleError($e);
                } finally {
                    if ($iterationStarted) {
                        $this->afterIteration();
                    }
                }
            }
        } finally {
            $this->workerStopped();
        }
    }

    public function handleRequest(RequestInterface $request): void
    {
        $this->response = null;
        $scope = $this->beginLifecycle($request);
        $failure = null;

        // Clear static cache
        ModelRepository::clearInstances();

        $this->app->setRequest($request);
        assert($request === $this->app->getRequest(), 'Request set does not match');

        $session = $this->app->session;

        try {
            if (!$session->isInitialized()) {
                $session->init();
            }

            $this->respond(
                $this->app->run()
                    ->withAddedHeader('Content-Language', $this->app->translations->getLang())
                    ->withAddedHeader('Set-Cookie', $session->getCookieHeader())
            );
        } catch (DispatchBreakException $e) {
            // Dispatch break exception is a special case allowing to create a response from anywhere.
            $this->respond(
                $e->getResponse()
                    ->withAddedHeader('Set-Cookie', $session->getCookieHeader())
            );
        } catch (Throwable $e) {
            $this->recordLifecycleException($scope, $e);
            try {
                $this->handleError($e);
            } catch (Throwable $handlerException) {
                $this->recordLifecycleException($scope, $handlerException);
                $failure = $handlerException;
            }
        } finally {
            try {
                $session->close();
            } catch (Throwable $exception) {
                $this->recordLifecycleException($scope, $exception);
                $this->reportAfterResponseError($exception);
            }

            try {
                $this->app->translations->updateTranslations();
            } catch (Throwable $exception) {
                $this->recordLifecycleException($scope, $exception);
                $this->reportAfterResponseError($exception);
            }

            try {
                $scope?->complete($this->response);
            } catch (Throwable) {
                // Lifecycle hooks must never affect request handling.
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function beginLifecycle(RequestInterface $request): ?RequestLifecycleScopeInterface
    {
        try {
            return $this->requestLifecycleHook?->begin($request);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordLifecycleException(
        ?RequestLifecycleScopeInterface $scope,
        Throwable $exception
    ): void {
        try {
            $scope?->recordException($exception);
        } catch (Throwable) {
            // Lifecycle hooks must never affect request handling.
        }
    }

    private function afterIteration(): void
    {
        try {
            $this->workerLifecycleHook?->afterIteration();
        } catch (Throwable) {
            // Lifecycle hooks must never affect worker execution.
        }
    }

    private function workerStopped(): void
    {
        try {
            $this->workerLifecycleHook?->workerStopped();
        } catch (Throwable) {
            // Lifecycle hooks must never affect worker execution.
        }
    }

    private function respond(\Psr\Http\Message\ResponseInterface $response): void
    {
        $this->psr7->respond($response);
        $this->response = $response;
    }

    public function handleError(Throwable $error): void
    {
        $request = $this->app->getRequest();
        assert($request instanceof Request);

        if ($error instanceof RouteNotFoundException) {
            $this->respond($this->error404Handler->showError($request, $error));
            return;
        }
        if ($error instanceof AccessDeniedException) {
            $this->respond($this->error403Handler->showError($request, $error));
            return;
        }
        if ($error instanceof MethodNotAllowedException) {
            $this->respond($this->error405Handler->showError($request, $error));
            return;
        }

        $this->reportError($error);

        if (!$this->app->isProduction()) {
            ob_start(); // double buffer prevents sending HTTP headers in some PHP
            ob_start();
            Debugger::getBlueScreen()->render($error);
            /** @var string $blueScreen */
            $blueScreen = ob_get_clean();
            ob_end_clean();

            $this->respond(
                new Response(
                    500,
                    [
                        'Content-Type' => 'text/html',
                    ],
                    $blueScreen
                )
            );
            return;
        }

        $this->respond($this->error500Handler->showError($request, $error));
    }
    private function reportError(Throwable $error): void
    {
        $this->logger->exception($error);
        Helpers::improveException($error);
        Debugger::log($error, ILogger::EXCEPTION);
        file_put_contents('php://stderr', (string) $error);
    }

    private function reportAfterResponseError(Throwable $error): void
    {
        try {
            $this->reportError($error);
        } catch (Throwable) {
            // Error reporting must not cause a second response after the request completed.
        }
    }
}
