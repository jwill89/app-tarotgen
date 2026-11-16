<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminReadingController;
use Tarot\Utility\Clock;
use Tarot\Repository\ReadingRepository;
use Tarot\Data\ReadingData;

/**
 * Stored readings from the admin side: deleting one, and the retention purge,
 * which only ever removes guest readings past the chosen age.
 */
#[CoversClass(AdminReadingController::class)]
#[CoversClass(ReadingRepository::class)]
#[CoversClass(ReadingData::class)]
final class AdminReadingsTest extends HttpTestCase
{
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser('reader@example.test')->user_id;
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testDeletingAReadingRemovesIt(): void
    {
        $this->reading('keep000001', daysAgo: 1);
        $this->reading('drop000001', daysAgo: 1, ownerId: $this->ownerId);

        $this->assertSame(204, $this->request('DELETE', '/admin/readings/drop000001')->getStatusCode());

        $this->assertSame(['keep000001'], array_column($this->rows('SELECT reading_id FROM readings'), 'reading_id'));
    }

    public function testDeletingAReadingThatIsGoneIsNotFound(): void
    {
        $this->assertSame('Reading not found', $this->assertProblem(404, $this->request('DELETE', '/admin/readings/nosuch0001')));
    }

    public function testThePurgeRemovesOnlyGuestReadingsPastTheWindow(): void
    {
        $this->reading('guestold01', daysAgo: 45);
        $this->reading('guestold02', daysAgo: 31);
        $this->reading('guestnew01', daysAgo: 29);
        $this->reading('ownedold01', daysAgo: 400, ownerId: $this->ownerId);

        $body = $this->assertJsonResponse(200, $this->request('DELETE', '/admin/readings/all?older_than_days=30'));

        $this->assertDocumented('delete', '/admin/readings/all', 200, $body);
        $this->assertSame(['deleted' => 2], $body);
        $this->assertSame(['guestnew01', 'ownedold01'], array_column($this->rows('SELECT reading_id FROM readings ORDER BY reading_id'), 'reading_id'));

        $this->assertSame(['deleted' => 0], $this->assertJsonResponse(200, $this->request('DELETE', '/admin/readings/all?older_than_days=365')));
    }

    public function testThePurgeOnlyTakesAnAllowedWindow(): void
    {
        $this->reading('guestold01', daysAgo: 45);
        $invalid = 'Invalid day range.';

        foreach (['', '?older_than_days=1', '?older_than_days=31', '?older_than_days=-7', '?older_than_days=week'] as $query) {
            $this->assertSame($invalid, $this->assertProblem(422, $this->request('DELETE', "/admin/readings/all{$query}")), $query);
        }
        $this->assertSame(1, $this->rowCount('readings'));
    }

    public function testTheListPageSizeIsAtLeastOne(): void
    {
        $this->reading('older00001', daysAgo: 2);
        $this->reading('newer00001', daysAgo: 1);

        $page = $this->assertJsonResponse(200, $this->get('/admin/readings?limit=0&offset=-5'));

        $this->assertSame(2, $page['total']);
        $this->assertSame(['newer00001'], array_column($page['rows'], 'reading_id'));
    }

    public function testReadingAdministrationNeedsAnAdmin(): void
    {
        $this->reading('guestold01', daysAgo: 400);
        $this->signInAs($this->ownerId);

        $this->assertProblem(403, $this->get('/admin/readings'));
        $this->assertProblem(403, $this->request('DELETE', '/admin/readings/all?older_than_days=7'));
        $this->assertProblem(403, $this->request('DELETE', '/admin/readings/guestold01'));
        $this->assertSame(1, $this->rowCount('readings'));
    }

    private function reading(string $id, int $daysAgo, ?int $ownerId = null): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES (?, ?, ?, ?)')
            ->execute([$id, '{"deck_id":1}', gmdate(Clock::FORMAT, time() - $daysAgo * 86400), $ownerId]);
    }
}
