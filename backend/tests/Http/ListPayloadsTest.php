<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AccountController;
use Routes\Internal\AdminReadingController;
use Routes\Internal\PasskeyController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\FavoriteSpreadData;
use Tarot\Data\PasskeyData;

/**
 * The lists whose rows are Structures send what the spec documents for them,
 * not a bare "object". The admin readings list also works out each row's owner,
 * deck and spread in SQL, a page at a time.
 */
#[CoversClass(AdminReadingController::class)]
#[CoversClass(PasskeyController::class)]
#[CoversClass(AccountController::class)]
final class ListPayloadsTest extends HttpTestCase
{
    public function testTheAdminReadingsListNamesOwnersDecksAndSpreads(): void
    {
        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $owner = $this->createUser('morgan@example.test');

        $insert = $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES (?, ?, ?, ?)');
        $insert->execute(['guest00001', json_encode(['deck_id' => $deck->deck_id, 'spread' => ['name' => 'Celtic Cross']]), '2026-01-01 10:00:00', null]);
        // A deck deleted since, and a free draw with no spread.
        $insert->execute(['nodeck0002', json_encode(['deck_id' => 999]), '2026-01-02 10:00:00', $owner->user_id]);
        $insert->execute(['newest0003', json_encode(['deck_id' => $deck->deck_id, 'spread' => ['name' => 'Three Card']]), '2026-01-03 10:00:00', $owner->user_id]);

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $first = $this->assertJsonResponse(200, $this->get('/admin/readings?limit=2'));
        $this->assertDocumented('get', '/admin/readings', 200, $first);
        $this->assertSame(3, $first['total']);
        $this->assertSame([
            [
                'reading_id' => 'newest0003', 'reading_time' => '2026-01-03 10:00:00', 'user_id' => $owner->user_id,
                'display_name' => 'morgan', 'deck_id' => $deck->deck_id, 'deck_name' => 'Test Deck', 'spread_name' => 'Three Card',
            ],
            [
                'reading_id' => 'nodeck0002', 'reading_time' => '2026-01-02 10:00:00', 'user_id' => $owner->user_id,
                'display_name' => 'morgan', 'deck_id' => 999, 'deck_name' => 'Deck #999', 'spread_name' => '',
            ],
        ], $first['rows']);

        $last = $this->assertJsonResponse(200, $this->get('/admin/readings?limit=2&offset=2'));
        $this->assertSame([
            [
                'reading_id' => 'guest00001', 'reading_time' => '2026-01-01 10:00:00', 'user_id' => null,
                'display_name' => 'Guest', 'deck_id' => $deck->deck_id, 'deck_name' => 'Test Deck', 'spread_name' => 'Celtic Cross',
            ],
        ], $last['rows']);
    }

    public function testThePasskeyListSendsWhatItDocuments(): void
    {
        $user = $this->createUser('keys@example.test');
        $this->service(PasskeyData::class)->create($user->user_id, 'cred-abc', '-----PEM-----', 'Laptop', 0);
        $this->signInAs($user->user_id);

        $list = $this->assertJsonResponse(200, $this->get('/auth/passkeys'));

        // Documented, so no public_key_pem or sign_count: only sign-in reads those.
        $this->assertDocumented('get', '/auth/passkeys', 200, $list);
        $this->assertSame(['cred-abc'], array_column($list, 'credential_id'));
    }

    public function testTheFavouritesListSendsWhatItDocuments(): void
    {
        $user = $this->createUser('fan@example.test');
        $this->service(FavoriteSpreadData::class)->add($user->user_id, 'personal', 7);
        $this->signInAs($user->user_id);

        $list = $this->assertJsonResponse(200, $this->get('/account/favorites'));

        $this->assertDocumented('get', '/account/favorites', 200, $list);
        $this->assertSame([['spread_type' => 'personal', 'spread_id' => 7]], $list);
    }
}
