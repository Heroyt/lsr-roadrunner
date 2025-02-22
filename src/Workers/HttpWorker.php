<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\Workers;

use Lsr\Core\App;
use Lsr\Core\Requests\Exceptions\RouteNotFoundException;
use Lsr\Core\Requests\Request;
use Lsr\Core\Requests\RequestFactory;
use Lsr\Core\Routing\Exceptions\AccessDeniedException;
use Lsr\Interfaces\RequestInterface;
use Lsr\Logging\Logger;
use Lsr\Orm\ModelRepository;
use Lsr\Roadrunner\ErrorHandlers\HttpErrorHandler;
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
    /** @phpstan-ignore property.onlyRead */
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

    public function __construct(
      private readonly HttpErrorHandler $error500Handler,
      private readonly HttpErrorHandler $error404Handler,
      private readonly HttpErrorHandler $error403Handler,
    ) {
        $this->worker = RrWorker::create();

        $factory = new Psr17Factory();
        $this->psr7 = new PSR7Worker($this->worker, $factory, $factory, $factory);
    }

    public function run() : void {
        $request = null;
        while (true) {
            if (isset($request)) {
                unset($request);
            }

            try {
                try {
                    $request = $this->psr7->waitRequest();
                    if ($request === null) {
                        break;
                    }
                    $request = RequestFactory::fromPsrRequest($request);
                } catch (Throwable $e) {
                    // Although the PSR-17 specification clearly states that there can be
                    // no exceptions when creating a request, however, some implementations
                    // may violate this rule. Therefore, it is recommended to process the
                    // incoming request for errors.
                    //
                    // Send "Bad Request" response.
                    $this->psr7->respond(new Response(400, body: $e->getMessage()));
                    continue;
                }
                $this->handleRequest($request);
            } catch (Throwable $e) {
                $this->handleError($e);
            }
        }
    }

    public function handleRequest(RequestInterface $request) : void {
        // Clear static cache
        ModelRepository::clearInstances();

        $this->app->setRequest($request);
        assert($request === $this->app->getRequest(), 'Request set does not match');

        $session = $this->app->session;

        try {
            if (!$session->isInitialized()) {
                $session->init();
            }

            $this->psr7->respond(
              $this->app->run()
                        ->withAddedHeader('Content-Language', $this->app->translations->getLang())
                        ->withAddedHeader('Set-Cookie', $session->getCookieHeader())
            );
            $session->close();
            $this->app->translations->updateTranslations();
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    public function handleError(Throwable $error) : void {
        $this->logger->exception($error);
        Helpers::improveException($error);
        Debugger::log($error, ILogger::EXCEPTION);

        $request = $this->app->getRequest();
        assert($request instanceof Request);

        if ($error instanceof RouteNotFoundException) {
            $this->psr7->respond($this->error404Handler->showError($request, $error));
            return;
        }
        if ($error instanceof AccessDeniedException) {
            $this->psr7->respond($this->error403Handler->showError($request, $error));
            return;
        }

        file_put_contents('php://stderr', (string) $error);

        if (!$this->app->isProduction()) {
            ob_start(); // double buffer prevents sending HTTP headers in some PHP
            ob_start();
            Debugger::getBlueScreen()->render($error);
            /** @var string $blueScreen */
            $blueScreen = ob_get_clean();
            ob_end_clean();

            $this->psr7->respond(
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

        $this->psr7->respond($this->error500Handler->showError($request, $error));
    }
}