<?php

namespace Routes;

use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

/**
 * Slim's own errors — an unknown route, a method a route doesn't take, an
 * uncaught exception — in the API's error shape, `{ "error": "…" }`, rather
 * than Slim's `{ "message": … }` or an HTML page. Outside production the
 * exception chain rides along under `exception`, as Slim's own renderer does.
 */
final class JsonErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        // An HttpException's message is written for the client ("Not found.",
        // "Method not allowed. Must be one of: GET"); any other may carry internals.
        $body = ['error' => $exception instanceof HttpException
            ? $exception->getMessage()
            : 'Something went wrong on our side. Please try again.'];

        if ($displayErrorDetails) {
            $body['exception'] = [];
            for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
                $body['exception'][] = [
                    'type'    => $e::class,
                    'code'    => $e->getCode(),
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ];
            }
        }

        return json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
