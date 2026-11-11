<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PDOException;
use Tarot\Data\ReadingData;
use Tarot\Data\ReadingFilter;
use Tarot\Structure\Reading;
use Tarot\Structure\ReadingSpread;
use Tarot\Structure\ReadingTag;
use Tarot\Utility\Clock;

#[CoversClass(ReadingData::class)]
final class ReadingDataTest extends TestCase
{
    private PDO $pdo;
    private ReadingData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE readings (
                reading_id TEXT PRIMARY KEY,
                reading_info TEXT NOT NULL,
                reading_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT NULL,
                hide_user INTEGER NOT NULL DEFAULT 0,
                reading_name TEXT DEFAULT NULL,
                reading_notes TEXT DEFAULT NULL,
                is_final INTEGER NOT NULL DEFAULT 0,
                password_hash TEXT DEFAULT NULL,
                question TEXT DEFAULT NULL,
                querent TEXT DEFAULT NULL,
                is_private INTEGER NOT NULL DEFAULT 0,
                expires_at TEXT DEFAULT NULL
            )'
        );
        // A reading's tags (as in db/schema.sql), cascading with it.
        $this->pdo->exec(
            'CREATE TABLE reading_tags (
                reading_id TEXT NOT NULL REFERENCES readings(reading_id) ON DELETE CASCADE,
                tag TEXT NOT NULL,
                PRIMARY KEY (reading_id, tag)
            )'
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        // Read for Reading::$daily_card_day (as in db/schema.sql, minus users).
        $this->pdo->exec(
            'CREATE TABLE daily_cards (
                user_id INTEGER NOT NULL,
                day TEXT NOT NULL,
                reading_id TEXT DEFAULT NULL REFERENCES readings(reading_id) ON DELETE SET NULL,
                PRIMARY KEY (user_id, day)
            )'
        );
        $this->data = new ReadingData($this->pdo);
    }

    private function make(string $id, ?int $userId): Reading
    {
        $r = new Reading();
        $r->setReadingId($id);
        $r->setReadingInfo('{"deck_id":1,"draw":[]}');
        if ($userId !== null) {
            $r->setUserId($userId);
        }
        return $r;
    }

    /** The stored reading $id, failing the test when there is none. */
    private function retrieved(string $id): Reading
    {
        $reading = $this->data->retrieve($id);
        $this->assertNotNull($reading, "No reading '{$id}'");

        return $reading;
    }

    public function testListByUserPagesNewestFirstAndCounts(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO readings (reading_id, reading_info, reading_time, user_id)
             VALUES (:id, '{}', datetime('now', :age), :uid)"
        );
        foreach (['r1' => '-3 days', 'r2' => '-2 days', 'r3' => '-1 days'] as $id => $age) {
            $insert->execute([':id' => $id, ':age' => $age, ':uid' => 7]);
        }
        $insert->execute([':id' => 'other', ':age' => '-1 days', ':uid' => 8]);

        $ids = static fn(array $readings): array => array_map(static fn(Reading $r) => $r->reading_id, $readings);

        $this->assertSame(['r3', 'r2', 'r1'], $ids($this->data->listByUser(7)));
        $this->assertSame(['r3', 'r2'], $ids($this->data->listByUser(7, 2)));
        $this->assertSame(['r1'], $ids($this->data->listByUser(7, 2, 2)));
        $this->assertSame(3, $this->data->countByUser(7));
        $this->assertSame(0, $this->data->countByUser(99));
    }

    public function testCleanGuestOlderThanDeletesOnlyOldGuestReadings(): void
    {
        // The nightly retention job (scripts/retention.php) relies on this: guest
        // readings past the window go; account readings never do.
        $insert = $this->pdo->prepare(
            "INSERT INTO readings (reading_id, reading_info, reading_time, user_id)
             VALUES (:id, '{}', datetime('now', :age), :uid)"
        );
        $insert->execute([':id' => 'old-guest', ':age' => '-366 days', ':uid' => null]);
        $insert->execute([':id' => 'recent-guest', ':age' => '-364 days', ':uid' => null]);
        $insert->execute([':id' => 'old-owned', ':age' => '-900 days', ':uid' => 7]);

        $this->assertSame(1, $this->data->cleanGuestOlderThan(365));

        $this->assertNull($this->data->retrieve('old-guest'));
        $this->assertNotNull($this->data->retrieve('recent-guest'));
        $this->assertNotNull($this->data->retrieve('old-owned'));
    }

    public function testStoreAndRetrieveNeverExposesTheHashButFlagsProtection(): void
    {
        $r = $this->make('r1', 7);
        $r->setReadingName('My Spread');
        $r->setHideUser(true);
        $this->data->store($r, password_hash('secret123', PASSWORD_DEFAULT));

        $loaded = $this->data->retrieve('r1');
        $this->assertNotNull($loaded);
        $this->assertSame(7, $loaded->user_id);
        $this->assertSame('My Spread', $loaded->reading_name);
        $this->assertTrue($loaded->hide_user);
        $this->assertTrue($loaded->password_protected);

        // The serialized form must not contain the hash.
        $this->assertArrayNotHasKey('password_hash', $loaded->jsonSerialize());
    }

    public function testADailyCardReadingCarriesItsDay(): void
    {
        $this->data->store($this->make('daily', 4));
        $this->data->store($this->make('plain', 4));
        $this->pdo->exec("INSERT INTO daily_cards (user_id, day, reading_id) VALUES (4, '2026-10-30', 'daily')");
        // A day whose reading was deleted belongs to no reading.
        $this->pdo->exec("INSERT INTO daily_cards (user_id, day, reading_id) VALUES (4, '2026-10-29', NULL)");

        $this->assertSame('2026-10-30', $this->retrieved('daily')->daily_card_day);
        $this->assertNull($this->retrieved('plain')->daily_card_day);
        $this->assertSame(
            ['plain' => null, 'daily' => '2026-10-30'],
            array_column(array_map(static fn(Reading $r): array => $r->jsonSerialize(), $this->data->listByUser(4)), 'daily_card_day', 'reading_id')
        );
    }

    public function testReadingNotesRoundTripAndUpdate(): void
    {
        $r = $this->make('notes1', 3);
        $r->setReadingNotes('# Heading\n\nSome **markdown** notes.');
        $this->data->store($r);

        $loaded = $this->data->retrieve('notes1');
        $this->assertNotNull($loaded);
        $this->assertSame('# Heading\n\nSome **markdown** notes.', $loaded->reading_notes);

        // Owner can edit; clearing sets it back to null.
        $this->data->updateMeta('notes1', 3, ['reading_notes' => 'Updated notes']);
        $this->assertSame('Updated notes', $this->retrieved('notes1')->reading_notes);

        $this->data->updateMeta('notes1', 3, ['reading_notes' => null]);
        $this->assertNull($this->retrieved('notes1')->reading_notes);
    }

    public function testVerifyPassword(): void
    {
        $this->data->store($this->make('r2', 1), password_hash('opensesame', PASSWORD_DEFAULT));
        $this->assertTrue($this->data->verifyPassword('r2', 'opensesame'));
        $this->assertFalse($this->data->verifyPassword('r2', 'wrong'));
        // A reading with no password never verifies.
        $this->data->store($this->make('r3', 1));
        $this->assertFalse($this->data->verifyPassword('r3', 'anything'));
    }

    public function testListByUserReturnsOnlyThatUsersReadings(): void
    {
        $this->data->store($this->make('a', 1));
        $this->data->store($this->make('b', 1));
        $this->data->store($this->make('c', 2));
        $this->data->store($this->make('d', null));

        $this->assertCount(2, $this->data->listByUser(1));
        $this->assertCount(1, $this->data->listByUser(2));
    }

    public function testDeleteForOwnerIsScopedToTheOwner(): void
    {
        $this->data->store($this->make('mine', 1));

        // Wrong owner can't delete it.
        $this->assertFalse($this->data->deleteForOwner('mine', 2));
        $this->assertNotNull($this->data->retrieve('mine'));

        // The owner can.
        $this->assertTrue($this->data->deleteForOwner('mine', 1));
        $this->assertNull($this->data->retrieve('mine'));
    }

    public function testMarkFinalIsScopedToTheOwnerAndIsIdempotent(): void
    {
        $this->data->store($this->make('lockme', 5));
        $this->assertFalseOrNullFinal('lockme');

        // Wrong owner can't lock it.
        $this->assertNull($this->data->markFinal('lockme', 99));
        $this->assertFalse($this->retrieved('lockme')->is_final);

        // The owner can, and a repeat call still reports the (final) state.
        $locked = $this->data->markFinal('lockme', 5);
        $this->assertNotNull($locked);
        $this->assertTrue($locked->is_final);

        $relocked = $this->data->markFinal('lockme', 5);
        $this->assertNotNull($relocked);
        $this->assertTrue($relocked->is_final);
    }

    private function assertFalseOrNullFinal(string $id): void
    {
        $this->assertFalse($this->retrieved($id)->is_final);
    }

    public function testUpdateReadingInfoAppendsDraw(): void
    {
        $this->data->store($this->make('grow', 8));

        $updated = $this->data->updateReadingInfo(
            'grow',
            '{"deck_id":1,"origin":"generated","draw":[{"card_id":4}]}',
            8
        );
        $this->assertNotNull($updated);

        $info = json_decode($updated->reading_info, true);
        $this->assertCount(1, $info['draw']);
        $this->assertSame(4, $info['draw'][0]['card_id']);

        // Wrong owner can't rewrite it.
        $this->assertNull($this->data->updateReadingInfo('grow', '{"deck_id":1,"draw":[]}', 99));
    }

    public function testUpdateMetaIsScopedToTheOwner(): void
    {
        $this->data->store($this->make('owned', 1));

        // Wrong owner → no update, null returned.
        $this->assertNull($this->data->updateMeta('owned', 99, ['reading_name' => 'Hacked']));

        // Correct owner → updates and can set + clear a password.
        $updated = $this->data->updateMeta('owned', 1, [
            'reading_name'  => 'Renamed',
            'hide_user'     => true,
            'password_hash' => password_hash('pw', PASSWORD_DEFAULT),
        ]);
        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated->reading_name);
        $this->assertTrue($updated->hide_user);
        $this->assertTrue($updated->password_protected);

        $cleared = $this->data->updateMeta('owned', 1, ['password_hash' => null]);
        $this->assertNotNull($cleared);
        $this->assertFalse($cleared->password_protected);
    }

    public function testTheJournalFieldsAndTagsRoundTripTagsSorted(): void
    {
        $r = $this->make('journal', 3);
        $r->setQuestion('Should I take the job?');
        $r->setQuerent('My sister');
        $r->setTags(['monthly', 'career']);
        $this->data->store($r);

        $loaded = $this->retrieved('journal');
        $this->assertSame('Should I take the job?', $loaded->question);
        $this->assertSame('My sister', $loaded->querent);
        $this->assertSame(['career', 'monthly'], $loaded->tags);
        $this->assertSame('link', $loaded->visibility);
        $this->assertNull($loaded->expires_at);
        // Neither access column is ever a field of its own.
        $this->assertArrayNotHasKey('is_private', $loaded->jsonSerialize());
        $this->assertArrayNotHasKey('password_hash', $loaded->jsonSerialize());

        // No tags read back as an empty list, not null.
        $this->data->store($this->make('untagged', 3));
        $this->assertSame([], $this->retrieved('untagged')->tags);
    }

    public function testVisibilityIsDerivedFromThePrivateFlagAndThePassword(): void
    {
        $this->data->store($this->make('open', 1));
        $this->data->store($this->make('locked', 1), password_hash('abcd', PASSWORD_DEFAULT));
        $this->data->store($this->make('private', 1)->setVisibility('private'));
        // Private wins should a password ever be left beside the flag.
        $this->data->store($this->make('both', 1)->setVisibility('private'), password_hash('abcd', PASSWORD_DEFAULT));

        $this->assertSame('link', $this->retrieved('open')->visibility);
        $this->assertSame('password', $this->retrieved('locked')->visibility);
        $this->assertSame('private', $this->retrieved('private')->visibility);
        $this->assertFalse($this->retrieved('private')->password_protected);
        $this->assertSame('private', $this->retrieved('both')->visibility);
        $this->assertSame(['both', 'private'], $this->column('SELECT reading_id FROM readings WHERE is_private = 1 ORDER BY reading_id'));
    }

    public function testUpdateMetaWritesTheJournalFieldsAndReplacesTheTags(): void
    {
        $this->data->store($this->make('owned', 1)->setTags(['old', 'stays']));

        // Tags alone are a change, and they replace the set.
        $retagged = $this->data->updateMeta('owned', 1, ['tags' => ['stays', 'new']]);
        $this->assertNotNull($retagged);
        $this->assertSame(['new', 'stays'], $retagged->tags);

        $updated = $this->data->updateMeta('owned', 1, ['question' => 'What now?', 'querent' => 'Sam', 'is_private' => true]);
        $this->assertNotNull($updated);
        $this->assertSame('What now?', $updated->question);
        $this->assertSame('Sam', $updated->querent);
        $this->assertSame('private', $updated->visibility);
        $this->assertSame(['new', 'stays'], $updated->tags);

        $cleared = $this->data->updateMeta('owned', 1, ['tags' => [], 'is_private' => false]);
        $this->assertNotNull($cleared);
        $this->assertSame([], $cleared->tags);
        $this->assertSame('link', $cleared->visibility);

        // Someone else's: nothing written, tags included.
        $this->assertNull($this->data->updateMeta('owned', 2, ['tags' => ['hacked']]));
        $this->assertNull($this->data->updateMeta('owned', 1, []));
        $this->assertSame([], $this->retrieved('owned')->tags);
    }

    public function testDeletingAReadingDeletesItsTags(): void
    {
        $this->data->store($this->make('tagged', 1)->setTags(['career']));
        $this->data->deleteForOwner('tagged', 1);

        $this->assertSame([], $this->column('SELECT tag FROM reading_tags'));
    }

    public function testAStoreThatFailsLeavesNoTagsBehind(): void
    {
        $this->data->store($this->make('taken', 1));

        try {
            $this->data->store($this->make('taken', 1)->setTags(['orphan']));
            $this->fail('A duplicate id was stored.');
        } catch (PDOException) {
            // The reading's insert failed, so its tags were never written.
        }

        $this->assertSame([], $this->column('SELECT tag FROM reading_tags'));
    }

    public function testAStoreJoinsATransactionAlreadyOpen(): void
    {
        $this->pdo->beginTransaction();
        $this->data->store($this->make('pending', 1)->setTags(['career']));
        $this->pdo->rollBack();

        $this->assertNull($this->data->retrieve('pending'));
        $this->assertSame([], $this->column('SELECT tag FROM reading_tags'));
    }

    public function testTagCountsAreTheUsersOwnAlphabetically(): void
    {
        $this->data->store($this->make('a', 1)->setTags(['monthly', 'career']));
        $this->data->store($this->make('b', 1)->setTags(['career']));
        $this->data->store($this->make('c', 2)->setTags(['love', 'career']));

        $counts = array_map(static fn(ReadingTag $t): array => [$t->tag, $t->count], $this->data->tagCounts(1));
        $this->assertSame([['career', 2], ['monthly', 1]], $counts);
        $this->assertSame([], $this->data->tagCounts(99));
    }

    public function testSpreadCountsAreTheUsersOwnByTheNameTheFilterFinds(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, ?)');
        $rows = [
            ['celtic', '{"spread":{"name":"Celtic Cross"},"draw":[]}', 1],
            ['shouted', '{"spread":{"name":" celtic cross "},"draw":[]}', 1],
            ['free', '{"deck_id":1,"draw":[]}', 1],
            ['null', '{"spread":null,"draw":[]}', 1],
            // Placed and named "Free Draw": the filter finds it with the unplaced ones.
            ['named-free', '{"spread":{"name":"Free Draw"},"draw":[]}', 1],
            ['placed', '{"spread":{"name":"Free Draw Placement"},"draw":[]}', 1],
            // Nothing to find these by.
            ['nameless', '{"spread":{"spread_id":0},"draw":[]}', 1],
            ['number', '{"spread":{"name":7},"draw":[]}', 1],
            ['blank', '{"spread":{"name":"  "},"draw":[]}', 1],
            ['broken', '{"spread":', 1],
            ['theirs', '{"spread":{"name":"Horseshoe"},"draw":[]}', 2],
        ];
        foreach ($rows as $row) {
            $insert->execute($row);
        }

        $counts = array_map(static fn(ReadingSpread $s): array => [$s->name, $s->count], $this->data->spreadCounts(1));

        $this->assertSame([['Celtic Cross', 2], ['Free Draw', 3], ['Free Draw Placement', 1]], $counts);
        foreach ($counts as [$name, $count]) {
            $this->assertSame($count, $this->data->countByUser(1, new ReadingFilter(spread: $name)), $name);
        }
        $this->assertSame([], $this->data->spreadCounts(99));
    }

    public function testAReadingPastItsExpiryIsGoneThoughNotYetDeleted(): void
    {
        $later = Clock::in(3600);
        $this->data->store($this->make('later', null)->setExpiresAt($later));
        $this->data->store($this->make('past', null)->setExpiresAt(Clock::in(-1)));

        $this->assertSame($later, $this->retrieved('later')->expires_at);
        $this->assertNull($this->data->retrieve('past'));
        $this->assertSame(['later', 'past'], $this->column('SELECT reading_id FROM readings ORDER BY reading_id'));
    }

    public function testDeleteExpiredGuestRemovesOnlyGuestReadingsPastTheirExpiry(): void
    {
        $this->data->store($this->make('expired', null)->setExpiresAt(Clock::in(-60)));
        $this->data->store($this->make('pending', null)->setExpiresAt(Clock::in(3600)));
        $this->data->store($this->make('no-expiry', null));
        // An account's reading never expires; were one ever marked, it still stays.
        $this->data->store($this->make('owned', 5)->setExpiresAt(Clock::in(-60)));

        $this->assertSame(1, $this->data->deleteExpiredGuest());
        $this->assertSame(['no-expiry', 'owned', 'pending'], $this->column('SELECT reading_id FROM readings ORDER BY reading_id'));
    }

    public function testPurgeGuestsReportsWhatItDeletesAndADryRunKeepsIt(): void
    {
        $this->data->store($this->make('expired', null)->setExpiresAt(Clock::in(-60)));
        $this->pdo->exec("INSERT INTO readings (reading_id, reading_info, reading_time) VALUES ('aged', '{}', datetime('now', '-400 days'))");
        $this->data->store($this->make('kept', null));

        $this->assertSame(['expired' => 1, 'aged' => 1], $this->data->purgeGuests(365, true));
        $this->assertSame(['aged', 'expired', 'kept'], $this->column('SELECT reading_id FROM readings ORDER BY reading_id'));

        $this->assertSame(['expired' => 1, 'aged' => 1], $this->data->purgeGuests(365, false));
        $this->assertSame(['kept'], $this->column('SELECT reading_id FROM readings'));
    }

    public function testAPurgeThatFailsLeavesNothingHalfDone(): void
    {
        // A database without the expiry column: the purge's first DELETE fails.
        $old = new PDO('sqlite::memory:');
        $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $old->exec("CREATE TABLE readings (reading_id TEXT PRIMARY KEY, reading_info TEXT NOT NULL, reading_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, user_id INTEGER)");

        try {
            new ReadingData($old)->purgeGuests(365, false);
            $this->fail('The purge ran without its column.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('expires_at', $e->getMessage());
        }

        $this->assertFalse($old->inTransaction());
    }

    public function testEachFilterAloneAndCombined(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO readings (reading_id, reading_info, reading_time, user_id, reading_name, reading_notes, question, querent)
             VALUES (:id, :info, :time, :uid, :name, :notes, :question, :querent)'
        );
        $rows = [
            ['celtic', ['deck_id' => 1, 'spread' => ['name' => 'Celtic Cross'], 'draw' => []], '2026-03-01 10:00:00', 1, 'Career check', null, null, null],
            ['mine', ['deck_id' => 2, 'spread' => ['name' => 'My Own Spread', 'spread_id' => 0], 'draw' => []], '2026-03-05 23:30:00', 1, null, 'Felt 100% sure', null, 'Alex'],
            ['free', ['deck_id' => 1, 'draw' => []], '2026-03-10 08:00:00', 1, null, null, 'Will it rain?', null],
            ['placed', ['deck_id' => 2, 'spread' => ['name' => 'Free Draw Placement'], 'draw' => []], '2026-03-20 08:00:00', 1, null, null, null, null],
            // Another user's reading matches most filters but is never in this user's list.
            ['theirs', ['deck_id' => 1, 'draw' => []], '2026-03-10 09:00:00', 2, 'Career', null, null, null],
        ];
        foreach ($rows as [$id, $info, $time, $uid, $name, $notes, $question, $querent]) {
            $insert->execute([
                ':id' => $id, ':info' => json_encode($info), ':time' => $time, ':uid' => $uid,
                ':name' => $name, ':notes' => $notes, ':question' => $question, ':querent' => $querent,
            ]);
        }
        $this->pdo->exec("INSERT INTO reading_tags (reading_id, tag) VALUES ('celtic', 'career'), ('free', 'career'), ('free', 'weather'), ('theirs', 'career')");
        $this->pdo->exec("INSERT INTO daily_cards (user_id, day, reading_id) VALUES (1, '2026-03-10', 'free')");

        $ids = fn(ReadingFilter $f): array => array_map(static fn(Reading $r): string => $r->reading_id, $this->data->listByUser(1, filter: $f));

        $this->assertSame(['placed', 'free', 'mine', 'celtic'], $ids(new ReadingFilter()));
        // Text: in the name, notes, question or querent, any case; % and _ are literal.
        $this->assertSame(['celtic'], $ids(new ReadingFilter(text: 'CAREER')));
        $this->assertSame(['mine'], $ids(new ReadingFilter(text: '100%')));
        $this->assertSame([], $ids(new ReadingFilter(text: '10_%')));
        $this->assertSame(['free'], $ids(new ReadingFilter(text: 'rain')));
        $this->assertSame(['mine'], $ids(new ReadingFilter(text: 'alex')));
        $this->assertSame(['free', 'celtic'], $ids(new ReadingFilter(deckId: 1)));
        // Spread by name, any case; "Free Draw" also finds a draw never placed.
        $this->assertSame(['mine'], $ids(new ReadingFilter(spread: 'my own spread')));
        $this->assertSame(['free'], $ids(new ReadingFilter(spread: 'Free Draw')));
        $this->assertSame(['placed'], $ids(new ReadingFilter(spread: 'Free Draw Placement')));
        $this->assertSame(['free', 'celtic'], $ids(new ReadingFilter(tag: 'career')));
        $this->assertSame(['free', 'mine'], $ids(new ReadingFilter(from: '2026-03-05 00:00:00', until: '2026-03-11 00:00:00')));
        $this->assertSame(['placed', 'free'], $ids(new ReadingFilter(from: '2026-03-06 00:00:00')));
        $this->assertSame(['mine', 'celtic'], $ids(new ReadingFilter(until: '2026-03-06 00:00:00')));
        $this->assertSame(['free'], $ids(new ReadingFilter(daily: true)));
        $this->assertSame(['placed', 'mine', 'celtic'], $ids(new ReadingFilter(daily: false)));
        // Combined: every one must hold.
        $this->assertSame(['celtic'], $ids(new ReadingFilter(deckId: 1, tag: 'career', daily: false)));
        $this->assertSame([], $ids(new ReadingFilter(text: 'career', deckId: 2)));

        $this->assertSame(2, $this->data->countByUser(1, new ReadingFilter(tag: 'career')));
        // A page of the filtered list: the second of the two.
        $this->assertSame(['celtic'], array_map(
            static fn(Reading $r): string => $r->reading_id,
            $this->data->listByUser(1, 1, 1, new ReadingFilter(tag: 'career'))
        ));
    }

    /** @return list<mixed> The first column of every row $sql returns. */
    private function column(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt);

        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
