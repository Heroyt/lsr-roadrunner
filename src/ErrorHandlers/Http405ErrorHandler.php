<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\ErrorHandlers;

use Lsr\Core\Requests\Dto\ErrorResponse;
use Lsr\Core\Requests\Enums\ErrorType;
use Lsr\Core\Requests\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Http405ErrorHandler implements HttpErrorHandler
{
    use BaseHttpErrorHandler;

    public function showError(Request $request, Throwable $error) : ResponseInterface {
        if (in_array('application/json', $this->getAcceptTypes($request))) {
            return new Response(
              405,
              ['Content-Type' => 'application/json'],
              json_encode(
                new ErrorResponse(
                             'Method '.$request->getMethod().' is not allowed for this route.',
                             ErrorType::ACCESS,
                  detail   : $error->getMessage(),
                  exception: $error
                ),
                JSON_THROW_ON_ERROR
              )
            );
        }

        if (in_array('text/html', $this->getAcceptTypes($request))) {
            return new Response(
              405,
              ['Content-Type' => 'text/plain'],
              <<<HTML
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                     <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
                     <meta http-equiv="X-UA-Compatible" content="ie=edge">
                     <title>Method not allowed</title>
                </head>
                <body>
                  <h1>Method not allowed</h1>
                  <p>{$error->getMessage()}</p>
                </body>
                </html>
                HTML,
            );
        }

        return new Response(405, ['Content-Type' => 'text/plain'], 'Method not allowed. - '.$error->getMessage());
    }
}