<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Middleware\JsonBodyParsing;
use Routes\Problem;
use Routes\ProblemErrorHandler;
use Tarot\Repository\UserRepository;

/**
 * Every error answers as RFC 9457 problem details, whatever the request's
 * Accept header: the controllers' own refusals, the auth guards', and Slim's
 * (an unknown route, a method a route doesn't take, a body that isn't a JSON
 * object, an uncaught exception).
 */
#[CoversClass(Problem::class)]
#[CoversClass(ProblemErrorHandler::class)]
#[CoversClass(JsonBodyParsing::class)]
final class ProblemDetailsTest extends HttpTestCase
{
    private const string PASSWORD = 'current passphrase 1';

    public function testAFailedValidationIsA422ListingEachField(): void
    {
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $response = $this->post('/account/spreads', ['name' => 'Crossroads', 'positions' => [['order' => 1, 'x' => 101]]]);

        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('x must be a number from 0 to 100.', $this->assertDocumentedProblem('post', '/account/spreads', 422, $response));
        $this->assertSame([
            'type'   => 'about:blank',
            'title'  => 'Unprocessable Entity',
            'status' => 422,
            'detail' => 'x must be a number from 0 to 100.',
            'errors' => [['message' => 'x must be a number from 0 to 100.', 'location' => 'body.positions[0].x']],
        ], $this->problemBody(422, $response));
    }

    public function testAQueryValueIsLocatedInTheQuery(): void
    {
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $response = $this->get('/account/card-frequency?days=0');

        $this->assertSame(['query.days' => 'days must be a whole number from 1 to 3650.'], $this->problemErrors($response));
    }

    public function testNotSignedInIsA401(): void
    {
        $response = $this->get('/account/spreads');

        $this->assertSame('Not authenticated', $this->assertDocumentedProblem('get', '/account/card-frequency', 401, $this->get('/account/card-frequency')));
        $this->assertSame(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'detail' => 'Not authenticated'], $this->problemBody(401, $response));
    }

    public function testSignedInButNotAllowedIsA403(): void
    {
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $response = $this->get('/admin/users');

        $this->assertSame(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'Only an admin can do that.'], $this->problemBody(403, $response));
    }

    public function testAWrongCurrentPasswordIsA403WhenChangingItOrDeletingTheAccount(): void
    {
        $users = $this->service(UserRepository::class);
        $user = $this->createUser('reader@example.test');
        $users->updatePasswordHash($user->user_id, password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        $this->signInAs($user->user_id);

        $change = $this->post('/account/change-password', ['current_password' => 'not it', 'new_password' => 'a brand new passphrase']);
        $this->assertSame('Your current password is incorrect.', $this->assertDocumentedProblem('post', '/account/change-password', 403, $change));

        $delete = $this->request('DELETE', '/account', ['password' => 'not it']);
        $this->assertSame('Password is incorrect.', $this->assertDocumentedProblem('delete', '/account', 403, $delete));

        $this->assertNotNull($users->findById($user->user_id));
    }

    public function testAnUnknownIdIsA404(): void
    {
        $response = $this->get('/readings/nosuchread');

        $this->assertSame('InvalidReadingID', $this->assertDocumentedProblem('get', '/readings/{reading_id}', 404, $response));
    }

    public function testARateLimitIsA429WithRetryAfter(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/auth/forgot-password', ['email' => "nobody{$attempt}@example.test"]);
        }

        $limited = $this->post('/auth/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame('Too many requests. Please try again later.', $this->assertDocumentedProblem('post', '/auth/forgot-password', 429, $limited));
        $retryAfter = (int) $limited->getHeaderLine('Retry-After');
        $this->assertGreaterThanOrEqual(3600, $retryAfter);
        $this->assertLessThanOrEqual(3601, $retryAfter);
    }

    public function testAnUnknownRouteIsA404WhateverTheRequestAccepts(): void
    {
        foreach (['application/json', 'text/html', 'application/xml', '*/*'] as $accept) {
            $response = $this->get('/no-such-route', ['Accept' => $accept]);

            $this->assertSame('Not found.', $this->assertProblem(404, $response), $accept);
            $this->assertArrayNotHasKey('exception', $this->problemBody(404, $response), 'a client mistake has no chain to show');
        }
    }

    public function testAMethodARouteDoesNotTakeIsA405KeepingAllow(): void
    {
        $response = $this->request('PATCH', '/config');

        $this->assertSame('Method not allowed. Must be one of: GET', $this->assertProblem(405, $response));
        $this->assertSame('GET', $response->getHeaderLine('Allow'));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'not JSON' => ['{"name": "Crossroads",', 'The request body is not valid JSON.'];
        yield 'a list' => ['[{"name": "Crossroads"}]', 'The request body must be a JSON object.'];
        yield 'an empty list' => ['[]', 'The request body must be a JSON object.'];
        yield 'a string' => ['"Crossroads"', 'The request body must be a JSON object.'];
        yield 'a number' => ['42', 'The request body must be a JSON object.'];
    }

    #[DataProvider('unreadableBodies')]
    public function testABodyThatIsNotAJsonObjectIsA400(string $body, string $error): void
    {
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $response = $this->sendRawJson('POST', '/account/spreads', $body);

        $this->assertSame($error, $this->assertProblem(400, $response));
        $this->assertSame(0, $this->rowCount('user_spreads'));
    }

    public function testAnEmptyBodyOrAnEmptyObjectIsNoBodyNotAnError(): void
    {
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        foreach (['', '  ', '{}'] as $body) {
            $this->assertSame(['body.name'], array_keys($this->problemErrors($this->sendRawJson('POST', '/account/spreads', $body))), var_export($body, true));
        }
    }

    public function testInProductionAnUnexpectedErrorSaysNothingAboutItself(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->db->exec('DROP TABLE spreads');

        $response = $this->get('/spreads');

        $this->assertSame(ProblemErrorHandler::UNEXPECTED, $this->assertProblem(500, $response));
        $this->assertSame(['type', 'title', 'status', 'detail'], array_keys($this->problemBody(500, $response)));
        $this->assertStringNotContainsString('spreads', (string) $response->getBody());
    }

    public function testOutsideProductionAnUnexpectedErrorCarriesItsChain(): void
    {
        $this->db->exec('DROP TABLE spreads');

        $response = $this->get('/spreads', ['Accept' => 'text/html']);

        $this->assertSame(ProblemErrorHandler::UNEXPECTED, $this->assertProblem(500, $response));
        $chain = $this->problemBody(500, $response)['exception'] ?? null;
        $this->assertIsArray($chain);
        $this->assertIsArray($chain[0]);
        $this->assertSame('PDOException', $chain[0]['type']);
        $this->assertStringContainsString('no such table: spreads', (string) $chain[0]['message']);
    }

    public function testACorsPreflightToARouteWithoutOptionsIsAnsweredEmpty(): void
    {
        $response = $this->request('OPTIONS', '/config');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }
}
