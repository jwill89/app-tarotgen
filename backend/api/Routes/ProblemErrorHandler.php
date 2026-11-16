<?php

namespace Routes;

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Handlers\ErrorHandler;

/**
 * Slim's own errors as problem details, whatever the request's Accept header:
 * an unknown route (404), a method a route doesn't take (405, with `Allow`), a
 * body that isn't a JSON object (400), and an uncaught exception (500).
 *
 * An HttpException's message is written for the client ("Not found."); any
 * other exception may carry internals, so it answers a generic message. Outside
 * production that 500 also carries the exception chain, under `exception`.
 *
 * Only a 5xx is logged. The 4xx are mostly scanners probing for /api/.env and
 * the like, and clients' mistakes, which aren't the server's to report.
 */
final class ProblemErrorHandler extends ErrorHandler
{
    public const string UNEXPECTED = 'Something went wrong on our side. Please try again.';

    protected function respond(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($this->statusCode);

        // Slim answers an OPTIONS request with no route (a CORS preflight) 200.
        if ($this->statusCode < 400) {
            return $response;
        }

        if ($this->exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $this->exception->getAllowedMethods()));
        }

        $detail = $this->exception instanceof HttpException ? $this->exception->getMessage() : self::UNEXPECTED;
        $body   = Problem::body($this->statusCode, $response->getReasonPhrase(), $detail);

        if ($this->displayErrorDetails && $this->statusCode >= 500) {
            $body['exception'] = [];
            for ($e = $this->exception; $e !== null; $e = $e->getPrevious()) {
                $body['exception'][] = [
                    'type'    => $e::class,
                    'code'    => $e->getCode(),
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ];
            }
        }

        return Problem::withBody($response, $body);
    }

    protected function writeToErrorLog(): void
    {
        if ($this->statusCode >= 500) {
            parent::writeToErrorLog();
        }
    }
}
