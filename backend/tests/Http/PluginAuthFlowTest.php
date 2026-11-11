<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\PluginAuthController;
use Tarot\Repository\PluginAuthCodeRepository;
use Tarot\Repository\PluginTokenRepository;
use Tarot\Service\PluginTokenService;

/**
 * Linking the Dalamud plugin to an account (PKCE): the signed-in browser
 * approves and gets a one-time code for the plugin's loopback listener; the
 * plugin swaps code + verifier for a Bearer token; the account lists and
 * revokes its linked tokens.
 */
#[CoversClass(PluginAuthController::class)]
#[CoversClass(PluginTokenService::class)]
#[CoversClass(PluginTokenRepository::class)]
#[CoversClass(PluginAuthCodeRepository::class)]
final class PluginAuthFlowTest extends HttpTestCase
{
    private const string REDIRECT = 'http://127.0.0.1:51234/callback';

    private int $userId;

    private string $verifier;

    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
        $this->verifier = bin2hex(random_bytes(32));
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function testApprovingInTheBrowserAndExchangingTheCodeLinksThePlugin(): void
    {
        $this->signInAs($this->userId);
        $approved = $this->assertJsonResponse(200, $this->post('/plugin/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT, 'state' => 'xyz',
        ]));
        $this->assertDocumented('post', '/plugin/authorize', 200, $approved);
        $this->assertIsString($approved['redirect_uri']);
        $this->assertStringStartsWith(self::REDIRECT . '?code=', $approved['redirect_uri']);
        parse_str((string) parse_url($approved['redirect_uri'], PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);
        $code = $query['code'];
        $this->assertIsString($code);

        // The plugin isn't the browser: it has no session.
        $this->useSessionCookie(null);
        $issued = $this->assertJsonResponse(200, $this->post('/plugin/token', ['code' => $code, 'code_verifier' => $this->verifier]));

        $this->assertDocumented('post', '/plugin/token', 200, $issued);
        $this->assertIsString($issued['token']);
        $this->assertStringStartsWith('tg_pat_', $issued['token']);
        $this->assertSame(['Bearer', 'account', 'reader'], [$issued['token_type'], $issued['scope'], $issued['display_name']]);
        // Only hashes are kept: a database leak gives away no working code or token.
        $this->assertSame(
            [['user_id' => $this->userId, 'token_hash' => hash('sha256', $issued['token'])]],
            $this->rows('SELECT user_id, token_hash FROM plugin_tokens')
        );
        $this->assertSame([['code_hash' => hash('sha256', $code), 'used' => 1]], $this->rows(
            'SELECT code_hash, used_at IS NOT NULL AS used FROM plugin_auth_codes'
        ));
        // Issuing the token isn't using it: Connected Apps says "never used" until the plugin does.
        $this->assertSame([['last_used_at' => null]], $this->rows('SELECT last_used_at FROM plugin_tokens'));

        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/account/readings', ['Authorization' => "Bearer {$issued['token']}"])));
    }

    public function testACodeIsOnlyForASignedInBrowser(): void
    {
        $body = ['code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT];
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/plugin/authorize', $body)));

        // A linked plugin can't mint codes (and so more tokens) with its own token.
        $token = $this->service(PluginTokenService::class)->issueToken($this->userId);
        $this->assertJsonResponse(401, $this->post('/plugin/authorize', $body, ['Authorization' => "Bearer {$token}"]));

        $this->assertSame(0, $this->rowCount('plugin_auth_codes'));
    }

    public function testApprovalNeedsAnS256Challenge(): void
    {
        $this->signInAs($this->userId);
        $required = ['error' => 'A valid S256 code_challenge is required.'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/plugin/authorize', ['redirect_uri' => self::REDIRECT])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/plugin/authorize', [
            'code_challenge' => $this->verifier, 'code_challenge_method' => 'plain', 'redirect_uri' => self::REDIRECT,
        ])));
        $this->assertSame(0, $this->rowCount('plugin_auth_codes'));

        $this->assertJsonResponse(200, $this->post('/plugin/authorize', [
            'code_challenge' => $this->challenge, 'code_challenge_method' => 's256', 'redirect_uri' => self::REDIRECT,
        ]));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function foreignRedirects(): iterable
    {
        yield 'another host' => ['http://evil.example/callback'];
        yield 'https to another host' => ['https://evil.example/callback'];
        yield 'https on loopback' => ['https://127.0.0.1:51234/callback'];
        yield 'a loopback-looking subdomain' => ['http://127.0.0.1.evil.example/callback'];
        yield 'loopback as the userinfo' => ['http://127.0.0.1@evil.example/callback'];
        // PHP's parse_url() reads this host as 127.0.0.1; a browser treats the
        // backslash as a slash and goes to evil.example.
        yield 'a backslash before the userinfo' => ['http://evil.example\@127.0.0.1/callback'];
        yield 'a custom scheme' => ['tarotgen://127.0.0.1/callback'];
        yield 'no scheme' => ['//127.0.0.1/callback'];
        yield 'nothing' => [''];
    }

    #[DataProvider('foreignRedirects')]
    public function testTheCodeIsOnlyEverSentToALoopbackAddress(string $redirect): void
    {
        $this->signInAs($this->userId);

        $response = $this->post('/plugin/authorize', ['code_challenge' => $this->challenge, 'redirect_uri' => $redirect]);

        $this->assertSame(['error' => 'redirect_uri must be a loopback address.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('plugin_auth_codes'));
    }

    /**
     * @return iterable<string,array{string,non-empty-string}>
     */
    public static function loopbackRedirects(): iterable
    {
        yield 'localhost' => ['http://localhost:51234/callback', 'http://localhost:51234/callback?code='];
        yield 'IPv6 loopback' => ['http://[::1]:51234/callback', 'http://[::1]:51234/callback?code='];
        yield 'with a query already' => ['http://127.0.0.1:51234/callback?install=2', 'http://127.0.0.1:51234/callback?install=2&code='];
    }

    /** @param non-empty-string $expectedPrefix */
    #[DataProvider('loopbackRedirects')]
    public function testEveryLoopbackFormIsAccepted(string $redirect, string $expectedPrefix): void
    {
        $this->signInAs($this->userId);

        $approved = $this->assertJsonResponse(200, $this->post('/plugin/authorize', ['code_challenge' => $this->challenge, 'redirect_uri' => $redirect]));

        $this->assertIsString($approved['redirect_uri']);
        $this->assertStringStartsWith($expectedPrefix, $approved['redirect_uri']);
    }

    public function testACodeWithTheWrongVerifierIsRefused(): void
    {
        $code = $this->approve();

        $wrong = $this->post('/plugin/token', ['code' => $code, 'code_verifier' => strrev($this->verifier)]);
        $this->assertSame(['error' => 'Invalid or expired authorization code.'], $this->assertJsonResponse(400, $wrong));
        $this->assertSame(0, $this->rowCount('plugin_tokens'));

        // A failed check doesn't spend the code: the real plugin can still finish.
        $this->assertJsonResponse(200, $this->post('/plugin/token', ['code' => $code, 'code_verifier' => $this->verifier]));
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $code = $this->approve();
        $this->assertJsonResponse(200, $this->post('/plugin/token', ['code' => $code, 'code_verifier' => $this->verifier]));

        $replayed = $this->post('/plugin/token', ['code' => $code, 'code_verifier' => $this->verifier]);

        $this->assertSame(['error' => 'Invalid or expired authorization code.'], $this->assertJsonResponse(400, $replayed));
        $this->assertSame(1, $this->rowCount('plugin_tokens'));
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $code = $this->approve();
        // Codes live five minutes; this one ran out a second ago.
        $this->db->exec("UPDATE plugin_auth_codes SET expires_at = datetime('now', '-1 second')");

        $response = $this->post('/plugin/token', ['code' => $code, 'code_verifier' => $this->verifier]);

        $this->assertSame(['error' => 'Invalid or expired authorization code.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('plugin_tokens'));
    }

    public function testAFreshCodeExpiresAfterFiveMinutes(): void
    {
        $this->approve();

        $ttl = $this->rows("SELECT CAST(ROUND((julianday(expires_at) - julianday('now')) * 86400) AS INTEGER) AS ttl FROM plugin_auth_codes");

        $this->assertEqualsWithDelta(300, $ttl[0]['ttl'], 2);
    }

    public function testAMissingOrMadeUpCodeIsRefused(): void
    {
        $invalid = ['error' => 'Invalid or expired authorization code.'];

        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/plugin/token', [])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/plugin/token', ['code' => $this->approve()])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/plugin/token', [
            'code' => bin2hex(random_bytes(32)), 'code_verifier' => $this->verifier,
        ])));
    }

    public function testExchangingCodesIsLimitedToTenAttemptsAMinute(): void
    {
        // Refused attempts count as much as good ones: a run of them is guessing.
        for ($i = 0; $i < 9; $i++) {
            $this->assertJsonResponse(400, $this->post('/plugin/token', ['code' => bin2hex(random_bytes(32)), 'code_verifier' => $this->verifier]));
        }
        $this->assertJsonResponse(200, $this->post('/plugin/token', ['code' => $this->approve(), 'code_verifier' => $this->verifier]));

        $refused = $this->post('/plugin/token', ['code' => $this->approve(), 'code_verifier' => $this->verifier]);

        $this->assertSame(['error' => 'Too many attempts. Please try again later.'], $this->assertJsonResponse(429, $refused));
        $this->assertSame(1, $this->rowCount('plugin_tokens'));
    }

    public function testTheTokenReadsItsUsersAccountButCannotChangeIt(): void
    {
        $other = $this->createUser('other@example.test');
        $insert = $this->db->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, ?)');
        $insert->execute(['mine000001', '{"deck_id":1,"draw":[]}', $this->userId]);
        $insert->execute(['theirs0002', '{"deck_id":1,"draw":[]}', $other->user_id]);
        $bearer = ['Authorization' => 'Bearer ' . $this->link()];

        $this->assertSame(['mine000001'], array_column($this->assertJsonResponse(200, $this->get('/account/readings', $bearer)), 'reading_id'));

        // Anything that changes or deletes account data needs the browser session.
        $this->assertJsonResponse(401, $this->request('PATCH', '/account/readings/mine000001', ['reading_name' => 'Renamed'], $bearer));
        $this->assertJsonResponse(401, $this->request('DELETE', '/account/readings/mine000001', null, $bearer));
        $this->assertJsonResponse(401, $this->post('/account/change-password', ['current_password' => 'x', 'new_password' => 'a brand new passphrase'], $bearer));
        $this->assertJsonResponse(401, $this->request('DELETE', '/account/tokens/1', null, $bearer));
        $this->assertSame([['reading_name' => null]], $this->rows("SELECT reading_name FROM readings WHERE reading_id = 'mine000001'"));
        $this->assertSame(2, $this->rowCount('readings'));
        $this->assertSame([['revoked' => 0]], $this->rows('SELECT revoked_at IS NOT NULL AS revoked FROM plugin_tokens'));
    }

    public function testUsingATokenRecordsWhenItWasLastUsed(): void
    {
        $token = $this->service(PluginTokenService::class)->issueToken($this->userId);
        $this->assertSame([['last_used_at' => null]], $this->rows('SELECT last_used_at FROM plugin_tokens'));

        $this->assertJsonResponse(200, $this->get('/account/favorites', ['Authorization' => "Bearer {$token}"]));

        // Written in UTC, as SQLite's own clock reads it, so the account page's "last used" is right.
        $this->assertSame([['just_now' => 1]], $this->rows(
            "SELECT last_used_at BETWEEN datetime('now', '-10 seconds') AND datetime('now') AS just_now FROM plugin_tokens"
        ));
    }

    public function testConnectedAppsListsOnlyYourTokensAndNeverTheirSecret(): void
    {
        $tokens = $this->service(PluginTokenService::class);
        $tokens->issueToken($this->userId, label: 'Laptop');
        $tokens->issueToken($this->userId, label: 'Desktop');
        $tokens->issueToken($this->createUser('other@example.test')->user_id, label: 'Theirs');
        $this->signInAs($this->userId);

        $list = $this->assertJsonResponse(200, $this->get('/account/tokens'));

        $this->assertDocumented('get', '/account/tokens', 200, $list);
        $this->assertEqualsCanonicalizing(['Laptop', 'Desktop'], array_column($list, 'label'));
        $this->assertSame([$this->userId, $this->userId], array_column($list, 'user_id'));
        $this->assertArrayNotHasKey('token_hash', $list[0]);
    }

    public function testRevokingATokenUnlinksThatPluginOnly(): void
    {
        $tokens = $this->service(PluginTokenService::class);
        $revoked = $tokens->issueToken($this->userId);
        $kept = $tokens->issueToken($this->userId);
        $this->signInAs($this->userId);
        $id = $this->tokenId($revoked);

        $this->assertSame(204, $this->request('DELETE', "/account/tokens/{$id}")->getStatusCode());

        // Still listed, now marked revoked, so the account page can show what was unlinked.
        $listed = array_column($this->assertJsonResponse(200, $this->get('/account/tokens')), 'revoked_at', 'id');
        $this->assertIsString($listed[$id]);
        $this->assertSame(['error' => 'Token not found.'], $this->assertJsonResponse(404, $this->request('DELETE', "/account/tokens/{$id}")));

        $this->useSessionCookie(null);
        $this->assertJsonResponse(401, $this->get('/account/readings', ['Authorization' => "Bearer {$revoked}"]));
        $this->assertJsonResponse(200, $this->get('/account/readings', ['Authorization' => "Bearer {$kept}"]));
    }

    public function testAnotherAccountsTokenCannotBeRevoked(): void
    {
        $other = $this->createUser('other@example.test');
        $theirs = $this->service(PluginTokenService::class)->issueToken($other->user_id);
        $this->signInAs($this->userId);

        $response = $this->request('DELETE', '/account/tokens/' . $this->tokenId($theirs));

        $this->assertSame(['error' => 'Token not found.'], $this->assertJsonResponse(404, $response));
        $this->assertSame(['error' => 'Token not found.'], $this->assertJsonResponse(404, $this->request('DELETE', '/account/tokens/999')));
        $this->useSessionCookie(null);
        $this->assertJsonResponse(200, $this->get('/account/readings', ['Authorization' => "Bearer {$theirs}"]));
    }

    /** Approve a link in a signed-in browser; returns the code, leaving no session behind. */
    private function approve(): string
    {
        $this->signInAs($this->userId);
        $approved = $this->assertJsonResponse(200, $this->post('/plugin/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT,
        ]));
        $this->useSessionCookie(null);

        $this->assertIsString($approved['redirect_uri']);
        parse_str((string) parse_url($approved['redirect_uri'], PHP_URL_QUERY), $query);
        $this->assertIsString($query['code']);
        return $query['code'];
    }

    /** The whole link flow; returns the plugin's token. */
    private function link(): string
    {
        $issued = $this->assertJsonResponse(200, $this->post('/plugin/token', ['code' => $this->approve(), 'code_verifier' => $this->verifier]));
        $this->assertIsString($issued['token']);
        return $issued['token'];
    }

    private function tokenId(string $token): int
    {
        $stmt = $this->db->prepare('SELECT id FROM plugin_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        return (int) $stmt->fetchColumn();
    }
}
