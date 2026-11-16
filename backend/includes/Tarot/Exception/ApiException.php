<?php

namespace Tarot\Exception;

use RuntimeException;
use Throwable;

/**
 * A domain-level failure that maps cleanly onto an HTTP response.
 *
 * Service-layer code throws this to signal a client-facing error (bad input,
 * missing resource, etc.) without knowing anything about HTTP. Controllers
 * catch it and answer with a problem details body carrying the status code and
 * message, keeping request/response concerns out of the services. A failed
 * validation names the field it refused by its location in the request
 * (`body.tags`, `query.from`), which the problem lists under `errors`.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 422,
        ?Throwable $previous = null,
        private readonly ?string $location = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Where the refused field was sent (`body.tags`), or null when no one field is at fault. */
    public function getLocation(): ?string
    {
        return $this->location;
    }
}
