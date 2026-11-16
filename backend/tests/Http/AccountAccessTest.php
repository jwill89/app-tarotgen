<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\AccountController;
use Routes\Internal\PluginAuthController;
use Routes\Middleware\AccountAuth;
use Routes\Middleware\UserAuth;

/**
 * Every account route turns away a visitor who isn't signed in, before it reads
 * or writes anything.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(PluginAuthController::class)]
#[CoversClass(AccountAuth::class)]
#[CoversClass(UserAuth::class)]
final class AccountAccessTest extends HttpTestCase
{
    /**
     * @return iterable<string,array{string,string,array<string,mixed>|null}>
     */
    public static function accountRoutes(): iterable
    {
        $routes = [
            ['GET', '/account/readings', null],
            ['PATCH', '/account/readings/abc123', ['reading_name' => 'Mine']],
            ['DELETE', '/account/readings/abc123', null],
            ['GET', '/account/readings/export?format=json', null],
            ['GET', '/account/readings/export?format=csv', null],
            ['GET', '/account/spreads', null],
            ['POST', '/account/spreads', ['name' => 'Mine', 'positions' => [['order' => 1]]]],
            ['PUT', '/account/spreads/1', ['name' => 'Mine']],
            ['DELETE', '/account/spreads/1', null],
            ['POST', '/account/spreads/1/submit', null],
            ['GET', '/account/favorites', null],
            ['POST', '/account/favorites', ['spread_type' => 'public', 'spread_id' => 1]],
            ['DELETE', '/account/favorites/public/1', null],
            ['GET', '/account/favorite-decks', null],
            ['POST', '/account/favorite-decks', ['deck_id' => 1]],
            ['DELETE', '/account/favorite-decks/1', null],
            ['POST', '/account/change-password', ['current_password' => 'x', 'new_password' => 'a brand new passphrase']],
            ['POST', '/account/sessions/end-others', null],
            ['PATCH', '/account', ['display_name' => 'Someone']],
            ['DELETE', '/account', ['password' => 'x']],
            ['GET', '/account/tokens', null],
            ['DELETE', '/account/tokens/1', null],
            ['GET', '/account/card-frequency', null],
            ['GET', '/account/reading-spreads', null],
            ['GET', '/account/daily-cards?month=2026-10', null],
            ['POST', '/account/daily-cards', ['day' => '2026-10-30', 'deck_id' => 1]],
            ['POST', '/plugin/authorize', ['code_challenge' => 'abc', 'redirect_uri' => 'http://127.0.0.1:5000/cb']],
        ];
        foreach ($routes as [$method, $path, $body]) {
            yield "{$method} {$path}" => [$method, $path, $body];
        }
    }

    /** @param array<string,mixed>|null $body */
    #[DataProvider('accountRoutes')]
    public function testAVisitorWhoIsNotSignedInIsTurnedAway(string $method, string $path, ?array $body): void
    {
        $this->createUser('reader@example.test');

        $response = $this->request($method, $path, $body);

        $this->assertSame('Not authenticated', $this->assertProblem(401, $response));
        $this->assertSame(1, $this->rowCount('users'));
        foreach (['user_spreads', 'user_favorite_spreads', 'user_favorite_decks', 'plugin_auth_codes', 'readings', 'daily_cards'] as $table) {
            $this->assertSame(0, $this->rowCount($table), $table);
        }
    }
}
