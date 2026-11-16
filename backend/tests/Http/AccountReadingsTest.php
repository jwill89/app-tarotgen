<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\AccountController;
use Tarot\Repository\ReadingRepository;
use Tarot\Structure\Reading;

/**
 * A signed-in user's own readings: the paged list, and renaming, annotating,
 * hiding, password-protecting and deleting one. None of it reaches another
 * account's readings, or a guest's.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(ReadingRepository::class)]
#[CoversClass(Reading::class)]
final class AccountReadingsTest extends HttpTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
    }

    public function testTheListHoldsOnlyYourReadingsNewestFirst(): void
    {
        $other = $this->createUser('other@example.test');
        $this->insertReading('older00001', $this->userId, '2026-01-01T09:00:00Z', passwordHash: password_hash('abcd', PASSWORD_BCRYPT));
        $this->insertReading('newer00002', $this->userId, '2026-01-02T09:00:00Z');
        $this->insertReading('theirs0003', $other->user_id, '2026-01-03T09:00:00Z');
        $this->insertReading('guest00004', null, '2026-01-04T09:00:00Z');
        $this->signInAs($this->userId);

        $response = $this->get('/account/readings');
        $list = $this->assertJsonResponse(200, $response);

        $this->assertDocumented('get', '/account/readings', 200, $list);
        $this->assertSame(['newer00002', 'older00001'], array_column($list, 'reading_id'));
        // The owner sees that a password is set, never the hash.
        $this->assertSame([false, true], array_column($list, 'password_protected'));
        // Without ?limit it's the plain array the plugin reads: no paging header.
        $this->assertFalse($response->hasHeader('X-Total-Count'));
    }

    public function testAPageOfReadingsComesWithTheTotalCount(): void
    {
        foreach (['2026-01-01', '2026-01-02', '2026-01-03'] as $n => $day) {
            $this->insertReading("reading000{$n}", $this->userId, "{$day}T09:00:00Z");
        }
        $this->signInAs($this->userId);

        $first = $this->get('/account/readings?limit=2');
        $this->assertSame(['reading0002', 'reading0001'], array_column($this->assertJsonResponse(200, $first), 'reading_id'));
        $this->assertSame('3', $first->getHeaderLine('X-Total-Count'));

        $rest = $this->get('/account/readings?limit=2&offset=2');
        $this->assertSame(['reading0000'], array_column($this->assertJsonResponse(200, $rest), 'reading_id'));
        $this->assertSame('3', $rest->getHeaderLine('X-Total-Count'));

    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function pagesOutOfRange(): iterable
    {
        yield 'no readings' => ['limit=0', 'query.limit', 'limit must be a whole number from 1 to 100.'];
        yield 'over the most a page holds' => ['limit=101', 'query.limit', 'limit must be a whole number from 1 to 100.'];
        yield 'not a number' => ['limit=ten', 'query.limit', 'limit must be a whole number from 1 to 100.'];
        yield 'a negative offset' => ['limit=2&offset=-5', 'query.offset', 'offset must be a whole number from 0.'];
    }

    #[DataProvider('pagesOutOfRange')]
    public function testAPageOutOfRangeIsRefusedNotClamped(string $query, string $location, string $error): void
    {
        $this->signInAs($this->userId);

        $response = $this->get('/account/readings?' . $query);

        $this->assertSame($error, $this->assertDocumentedProblem('get', '/account/readings', 422, $response));
        $this->assertSame([$location => $error], $this->problemErrors($response));
    }

    public function testAReadingCanBeRenamedAnnotatedAndHidden(): void
    {
        $this->insertReading('mine000001', $this->userId);
        $this->signInAs($this->userId);

        $updated = $this->assertJsonResponse(200, $this->request('PATCH', '/account/readings/mine000001', [
            'reading_name' => '  New moon  ',
            'reading_notes' => "## Notes\n\n    kept as typed",
            'hide_user' => true,
        ]));

        $this->assertDocumented('patch', '/account/readings/{reading_id}', 200, $updated);
        $this->assertSame('New moon', $updated['reading_name']);
        $this->assertSame([['reading_name' => 'New moon', 'reading_notes' => "## Notes\n\n    kept as typed", 'hide_user' => 1]], $this->rows(
            "SELECT reading_name, reading_notes, hide_user FROM readings WHERE reading_id = 'mine000001'"
        ));

        // A blank name or notes clears them; only the fields sent are touched.
        $this->assertJsonResponse(200, $this->request('PATCH', '/account/readings/mine000001', ['reading_name' => '   ', 'reading_notes' => '']));
        $this->assertSame([['reading_name' => null, 'reading_notes' => null, 'hide_user' => 1]], $this->rows(
            "SELECT reading_name, reading_notes, hide_user FROM readings WHERE reading_id = 'mine000001'"
        ));
    }

    public function testAViewPasswordCanBeSetAndRemoved(): void
    {
        $this->insertReading('mine000001', $this->userId);
        $this->signInAs($this->userId);

        $protected = $this->assertJsonResponse(200, $this->request('PATCH', '/account/readings/mine000001', ['password' => 'abcd']));
        $this->assertTrue($protected['password_protected']);
        $this->assertArrayNotHasKey('password_hash', $protected);

        $this->useSessionCookie(null);
        $this->assertTrue($this->assertJsonResponse(200, $this->get('/readings/mine000001'))['locked']);
        $this->assertJsonResponse(200, $this->post('/readings/mine000001/unlock', ['password' => 'abcd']));

        $this->useSessionCookie(null);
        $this->signInAs($this->userId);
        $removed = $this->assertJsonResponse(200, $this->request('PATCH', '/account/readings/mine000001', ['remove_password' => true, 'password' => 'ignored']));
        $this->assertFalse($removed['password_protected']);

        $this->useSessionCookie(null);
        $this->assertFalse($this->assertJsonResponse(200, $this->get('/readings/mine000001'))['locked']);
    }

    public function testAViewPasswordNeedsFourCharacters(): void
    {
        $this->insertReading('mine000001', $this->userId);
        $this->signInAs($this->userId);

        $response = $this->request('PATCH', '/account/readings/mine000001', ['password' => 'abc', 'reading_name' => 'Renamed']);

        $this->assertSame('Reading password must be at least 4 characters.', $this->assertProblem(422, $response));
        // Nothing else in the request was applied either.
        $this->assertSame([['reading_name' => null, 'password_hash' => null]], $this->rows(
            "SELECT reading_name, password_hash FROM readings WHERE reading_id = 'mine000001'"
        ));
    }

    public function testAnUpdateWithNothingToChangeIsRefused(): void
    {
        $this->insertReading('mine000001', $this->userId);
        $this->signInAs($this->userId);

        $noChanges = 'No changes provided.';
        $this->assertSame($noChanges, $this->assertProblem(422, $this->request('PATCH', '/account/readings/mine000001', [])));
        // An empty password means "leave it", not "set it to nothing".
        $this->assertSame($noChanges, $this->assertProblem(422, $this->request('PATCH', '/account/readings/mine000001', ['password' => ''])));
    }

    public function testSomeoneElsesReadingCannotBeChangedOrDeleted(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->insertReading('theirs0001', $owner->user_id);
        $this->insertReading('guest00002', null);
        $this->signInAs($this->userId);

        $notFound = 'Reading not found.';
        foreach (['theirs0001', 'guest00002', 'unknown003'] as $id) {
            $patch = $this->request('PATCH', "/account/readings/{$id}", ['reading_name' => 'Mine now', 'password' => 'abcd']);
            $this->assertSame($notFound, $this->assertProblem(404, $patch), $id);
            $this->assertSame($notFound, $this->assertProblem(404, $this->request('DELETE', "/account/readings/{$id}")), $id);
        }

        $this->assertSame(
            [['reading_id' => 'guest00002', 'reading_name' => null, 'password_hash' => null], ['reading_id' => 'theirs0001', 'reading_name' => null, 'password_hash' => null]],
            $this->rows('SELECT reading_id, reading_name, password_hash FROM readings ORDER BY reading_id')
        );
    }

    public function testDeletingAReadingRemovesItsLink(): void
    {
        $this->insertReading('mine000001', $this->userId);
        $this->insertReading('mine000002', $this->userId);
        $this->signInAs($this->userId);

        $response = $this->request('DELETE', '/account/readings/mine000001');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([['reading_id' => 'mine000002']], $this->rows('SELECT reading_id FROM readings'));
        $this->assertProblem(404, $this->get('/readings/mine000001'));
        $this->assertProblem(404, $this->request('DELETE', '/account/readings/mine000001'));
    }

    private function insertReading(string $id, ?int $userId, string $time = '2026-01-01T09:00:00Z', ?string $passwordHash = null): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time, user_id, password_hash) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, json_encode(['deck_id' => 1, 'origin' => 'generated', 'draw' => [['card_id' => 1, 'reversed' => false]]]), $time, $userId, $passwordHash]);
    }
}
