<?php

namespace Routes;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Tarot\Exception\ApiException;

/**
 * The API's error responses: RFC 9457 problem details, sent as
 * `application/problem+json` whatever the request's Accept header.
 *
 * Every body has `type` (always about:blank, so the status says what kind of
 * problem it is), `title` (the status's reason phrase), `status`, and `detail`:
 * the message a user reads. A failed validation (422) also lists each field that
 * failed in `errors`, each with its message and where the field was sent:
 * `body.email`, `query.limit`, `path.deck_id`, or `body` for the body as a whole.
 */
final class Problem
{
    public const string CONTENT_TYPE = 'application/problem+json';

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * $response with $status and a problem body saying $detail.
     *
     * @param array<string,string> $errors     Each failed field's message, keyed by its location.
     * @param array<string,mixed>  $extensions More members the problem carries for the client, beside the standard ones.
     */
    public static function respond(
        ResponseInterface $response,
        int $status,
        string $detail,
        array $errors = [],
        array $extensions = [],
    ): ResponseInterface {
        $response = $response->withStatus($status);

        return self::withBody($response, self::body($status, $response->getReasonPhrase(), $detail, $errors) + $extensions);
    }

    /** A 422 for one field: $message is the detail and that field's error. */
    public static function invalid(ResponseInterface $response, string $location, string $message): ResponseInterface
    {
        return self::respond($response, 422, $message, [$location => $message]);
    }

    /** A 429, with how many seconds until the limit lets the caller in again. */
    public static function tooManyRequests(ResponseInterface $response, string $detail, int $retryAfter): ResponseInterface
    {
        return self::respond($response, 429, $detail)->withHeader('Retry-After', (string) max(1, $retryAfter));
    }

    /** The problem a service reported: its status and message, and the field it names, if any. */
    public static function fromException(ResponseInterface $response, ApiException $exception): ResponseInterface
    {
        $location = $exception->getLocation();
        $message  = $exception->getMessage();

        return self::respond($response, $exception->getStatusCode(), $message, $location === null ? [] : [$location => $message]);
    }

    /**
     * The problem body for $status, whose reason phrase is $title.
     *
     * @param array<string,string> $errors Each failed field's message, keyed by its location.
     * @return array{type: string, title: string, status: int, detail: string, errors?: list<array{message: string, location: string}>}
     */
    public static function body(int $status, string $title, string $detail, array $errors = []): array
    {
        $body = [
            'type'   => 'about:blank',
            'title'  => $title === '' ? 'Error' : $title,
            'status' => $status,
            'detail' => $detail,
        ];

        if ($errors !== []) {
            $body['errors'] = [];
            foreach ($errors as $location => $message) {
                $body['errors'][] = ['message' => $message, 'location' => $location];
            }
        }

        return $body;
    }

    /**
     * $response with $body as its problem JSON, in place of anything it held.
     *
     * @param array<string,mixed> $body
     */
    public static function withBody(ResponseInterface $response, array $body): ResponseInterface
    {
        return $response
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withBody(new StreamFactory()->createStream(json_encode($body, self::JSON_FLAGS)));
    }
}
