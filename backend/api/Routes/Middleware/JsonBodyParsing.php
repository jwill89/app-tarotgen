<?php

namespace Routes\Middleware;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Middleware\BodyParsingMiddleware;
use stdClass;

/**
 * Slim's body parsing, refusing a JSON body the server can't read: one that
 * isn't JSON, or is JSON but not an object (every endpoint takes named fields).
 * Slim's own parser would hand the controller no body at all, so the request
 * would fail as if its fields were missing. Here it answers 400 instead. An
 * empty body is no body, as before.
 */
final class JsonBodyParsing extends BodyParsingMiddleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $mediaType = $this->getMediaType($request);

        if ($mediaType === 'application/json' || str_ends_with($mediaType ?? '', '+json')) {
            $raw = (string) $request->getBody();

            if (trim($raw) !== '') {
                try {
                    $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new HttpBadRequestException($request, 'The request body is not valid JSON.');
                }

                if (!$decoded instanceof stdClass) {
                    throw new HttpBadRequestException($request, 'The request body must be a JSON object.');
                }
            }
        }

        return parent::process($request, $handler);
    }
}
