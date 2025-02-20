<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\ErrorHandlers;

use Lsr\Core\Requests\Dto\ErrorResponse;
use Lsr\Core\Requests\Enums\ErrorType;
use Lsr\Core\Requests\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Http404ErrorHandler implements HttpErrorHandler
{
    use BaseHttpErrorHandler;

    public function showError(Request $request, Throwable $error) : ResponseInterface {
        if (in_array('application/json', $this->getAcceptTypes($request))) {
            return new Response(
              404,
              ['Content-Type' => 'application/json'],
              json_encode(
                new ErrorResponse(
                             'Oops, I cannot find this.',
                             ErrorType::NOT_FOUND,
                  detail   : $error->getMessage(),
                  exception: $error
                ),
                JSON_THROW_ON_ERROR
              )
            );
        }

        if (in_array('text/html', $this->getAcceptTypes($request))) {
            return new Response(
              404,
              ['Content-Type' => 'text/plain'],
              <<<HTML
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                     <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
                     <meta http-equiv="X-UA-Compatible" content="ie=edge">
                     <title>Not Found</title>
                </head>
                <body>
                  <h1>Oops, I cannot find this.</h1>
                  <p>{$error->getMessage()}</p>
                </body>
                </html>
                HTML,
            );
        }

        return new Response(404, ['Content-Type' => 'text/plain'], 'Oops, I cannot find this. - '.$error->getMessage());
    }
}