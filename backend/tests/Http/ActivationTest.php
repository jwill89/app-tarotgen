<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\UserController;
use Tarot\Service\AuthService;

/**
 * The emailed activation link: it works once, before it expires, for activation
 * only, and the database never holds a usable copy of it.
 */
#[CoversClass(UserController::class)]
#[CoversClass(AuthService::class)]
final class ActivationTest extends HttpTestCase
{
    private const array REFUSED = ['error' => 'This activation link is invalid or has expired.'];

    public function testAnActivationLinkWorksOnce(): void
    {
        $token = $this->signUp();

        $activated = $this->assertJsonResponse(200, $this->post('/auth/activate', ['token' => $token]));
        $this->assertDocumented('post', '/auth/activate', 200, $activated);
        $this->assertSame(['success' => true, 'message' => 'Your account is now active. You can log in.'], $activated);
        $this->assertSame([['is_active' => 1]], $this->rows('SELECT is_active FROM users'));

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/activate', ['token' => $token])));
    }

    public function testActivatingDoesNotSignYouIn(): void
    {
        $this->assertJsonResponse(200, $this->post('/auth/activate', ['token' => $this->signUp()]));

        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testAMissingOrMadeUpTokenIsRefused(): void
    {
        $this->signUp();

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/activate')));
        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/activate', ['token' => '   '])));
        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/activate', ['token' => str_repeat('ab', 32)])));
        $this->assertSame([['is_active' => 0]], $this->rows('SELECT is_active FROM users'));
    }

    public function testAnExpiredLinkLeavesTheAccountInactive(): void
    {
        $token = $this->signUp();
        $this->db->exec("UPDATE user_tokens SET expires_at = '2000-01-01 00:00:00'");

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/activate', ['token' => $token])));
        $this->assertSame([['is_active' => 0]], $this->rows('SELECT is_active FROM users'));
    }

    public function testAPasswordResetLinkCannotActivateAnAccount(): void
    {
        $this->signUp();
        $forgot = $this->assertJsonResponse(200, $this->post('/auth/forgot-password', ['email' => 'reader@example.test']));

        $resetToken = $this->tokenFrom($forgot['reset_link'] ?? null);
        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/activate', ['token' => $resetToken])));
        $this->assertSame([['is_active' => 0]], $this->rows('SELECT is_active FROM users'));
    }

    public function testTheDatabaseHoldsOnlyAHashOfTheToken(): void
    {
        $token = $this->signUp();

        $stored = $this->rows('SELECT type, token_hash FROM user_tokens');

        // A leaked database can't be turned into working activation links.
        $this->assertSame([['type' => 'activation', 'token_hash' => hash('sha256', $token)]], $stored);
    }

    /** Sign up as reader@example.test; returns the token from the activation link. */
    private function signUp(): string
    {
        $registered = $this->assertJsonResponse(201, $this->post('/auth/register', [
            'email' => 'reader@example.test', 'display_name' => 'Reader', 'password' => 'test-only-' . bin2hex(random_bytes(8)),
        ]));

        return $this->tokenFrom($registered['activation_link'] ?? null);
    }

    private function tokenFrom(mixed $link): string
    {
        $this->assertIsString($link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertIsString($query['token'] ?? null);
        return $query['token'];
    }
}
