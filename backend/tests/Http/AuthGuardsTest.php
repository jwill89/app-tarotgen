<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Middleware\AccountAuth;
use Routes\Middleware\AdminAuth;
use Routes\Middleware\UserAuth;
use Tarot\Repository\UserRepository;
use Tarot\Service\PluginTokenService;

/**
 * The three auth middleware, through the routes they guard: who gets in, the
 * 401 a visitor who isn't signed in gets, and the 403 a signed-in account that
 * isn't an admin gets from the admin routes.
 */
#[CoversClass(AdminAuth::class)]
#[CoversClass(UserAuth::class)]
#[CoversClass(AccountAuth::class)]
final class AuthGuardsTest extends HttpTestCase
{
    public function testAdminRoutesNeedAnActiveAdminAccount(): void
    {
        $this->assertSame('Not authenticated', $this->assertProblem(401, $this->get('/admin/auth-check')));

        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->assertSame('Only an admin can do that.', $this->assertProblem(403, $this->get('/admin/auth-check')));

        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $this->assertSame(['authenticated' => true], $this->assertJsonResponse(200, $this->get('/admin/auth-check')));
    }

    public function testRevokingAdminTakesEffectOnTheNextRequest(): void
    {
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $this->assertJsonResponse(200, $this->get('/admin/auth-check'));

        $this->service(UserRepository::class)->setAdmin($admin->user_id, false);

        $this->assertProblem(403, $this->get('/admin/auth-check'));
    }

    public function testAnInactiveAccountIsNotSignedIn(): void
    {
        $pending = $this->createUser('pending@example.test', admin: true, active: false);
        $this->signInAs($pending->user_id);

        $this->assertProblem(401, $this->get('/admin/auth-check'));
        $this->assertProblem(401, $this->get('/account/spreads'));
        $this->assertProblem(401, $this->get('/account/readings'));
    }

    public function testSessionOnlyRoutesNeedASignedInUser(): void
    {
        $this->assertSame('Not authenticated', $this->assertProblem(401, $this->get('/account/spreads')));

        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/account/spreads')));
    }

    public function testASessionForADeletedAccountIsRejected(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->service(UserRepository::class)->delete($reader->user_id);

        $this->assertProblem(401, $this->get('/account/spreads'));
    }

    public function testSigningInInOneBrowserDoesNotSignInAnother(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->assertJsonResponse(200, $this->get('/account/spreads'));

        $this->useSessionCookie(null);

        $this->assertProblem(401, $this->get('/account/spreads'));
    }

    public function testAPluginTokenOpensTheAccountReadRoutes(): void
    {
        $this->assertSame('Not authenticated', $this->assertProblem(401, $this->get('/account/readings')));

        $reader = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($reader->user_id);
        $bearer = ['Authorization' => "Bearer {$token}"];

        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/account/readings', $bearer)));
        $this->assertJsonResponse(200, $this->get('/account/favorites', $bearer));
        $this->assertJsonResponse(200, $this->get('/account/favorite-decks', $bearer));
    }

    public function testAPluginTokenCannotReachSessionOnlyRoutes(): void
    {
        $reader = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($reader->user_id);
        $bearer = ['Authorization' => "Bearer {$token}"];

        // Least privilege: a leaked token can't manage the account or its tokens.
        $this->assertProblem(401, $this->get('/account/spreads', $bearer));
        $this->assertProblem(401, $this->get('/account/tokens', $bearer));
        $this->assertProblem(401, $this->request('DELETE', '/account', null, $bearer));
        $this->assertProblem(401, $this->get('/admin/auth-check', $bearer));
    }

    public function testUnknownAndRevokedTokensAreRejected(): void
    {
        $this->assertProblem(401, $this->get('/account/readings', ['Authorization' => 'Bearer tg_not-a-real-token']));

        $reader = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($reader->user_id);

        // Revoked from the account page, in the browser session.
        $this->signInAs($reader->user_id);
        $tokens = $this->assertJsonResponse(200, $this->get('/account/tokens'));
        $this->assertCount(1, $tokens);
        $this->assertIsArray($tokens[0]);
        $response = $this->request('DELETE', '/account/tokens/' . $tokens[0]['id']);
        $this->assertSame(204, $response->getStatusCode());

        $this->useSessionCookie(null);
        $this->assertProblem(401, $this->get('/account/readings', ['Authorization' => "Bearer {$token}"]));
    }
}
