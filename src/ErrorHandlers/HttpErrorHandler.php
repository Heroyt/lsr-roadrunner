<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\ErrorHandlers;

use Lsr\Core\Requests\Request;
use Psr\Http\Message\ResponseInterface;

interface HttpErrorHandler
{

    public function showError(Request $request, \Throwable $error) : ResponseInterface;

}