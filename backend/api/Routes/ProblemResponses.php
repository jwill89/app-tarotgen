<?php

namespace Routes;

use OpenApi\Augmenter\Group;
use OpenApi\Spec\Header;
use OpenApi\Spec\MediaType;
use OpenApi\Spec\Response;
use OpenApi\Spec\Schema;
use OpenApi\Specification;
use OpenApi\Utils\PipeInterface;

/**
 * swagger-php augmenter, run when OpenApiSpec::builder() assembles the document
 * (`composer docs`, OpenApiSpecTest): every 4xx and 5xx response an operation
 * declares answers with a problem details body (Problem), so each gets
 * `application/problem+json` with the ProblemDetails schema unless it declares
 * its own content (a problem with members of its own, such as DailyCardTaken),
 * and every 429 gets the Retry-After header the rate limiters send.
 *
 * @implements PipeInterface<Specification>
 */
final class ProblemResponses implements PipeInterface
{
    private const string PROBLEM = '#/components/schemas/ProblemDetails';

    /** @return Specification */
    public function __invoke(mixed $payload): mixed
    {
        $payload->getWalker()->visit(Response::class, static function (Response $response): void {
            $status = (string) $response->response;
            if (preg_match('/^[45]\d\d$/', $status) !== 1) {
                return;
            }

            $response->content ??= [new MediaType(mediaType: Problem::CONTENT_TYPE, schema: new Schema(ref: self::PROBLEM))];

            if ($status === '429') {
                $response->headers ??= [];
                $response->headers[] = new Header(
                    header: 'Retry-After',
                    description: 'Seconds until the limit lets the caller in again.',
                    schema: new Schema(type: 'integer', minimum: 1),
                );
            }
        });

        return $payload;
    }

    /**
     * Before Cleanup, which drops a schema nothing references: until this runs,
     * nothing references ProblemDetails.
     */
    public function group(): Group
    {
        return Group::Resolve;
    }
}
