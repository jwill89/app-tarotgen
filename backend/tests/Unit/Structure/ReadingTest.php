<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Structure\Reading;

/**
 * Who a reading belongs to and who may open it, and how its tags arrive: as the
 * JSON array a row holds, or as a list.
 */
#[CoversClass(Reading::class)]
final class ReadingTest extends TestCase
{
    public function testOnlyTheOwnerCanOpenAPrivateReading(): void
    {
        $private = new Reading(['user_id' => 7, 'visibility' => 'private']);

        $this->assertTrue($private->isVisibleTo(7));
        $this->assertFalse($private->isVisibleTo(8));
        $this->assertFalse($private->isVisibleTo(null));
    }

    public function testAnyoneCanReachALinkOrPasswordReading(): void
    {
        foreach (['link', 'password'] as $visibility) {
            $reading = new Reading(['user_id' => 7, 'visibility' => $visibility]);
            $this->assertTrue($reading->isVisibleTo(null), $visibility);
            $this->assertTrue($reading->isVisibleTo(8), $visibility);
        }
    }

    public function testAGuestReadingIsNobodysOwn(): void
    {
        $guest = new Reading(['user_id' => null]);

        $this->assertFalse($guest->isOwnedBy(null));
        $this->assertFalse($guest->isOwnedBy(0));
        $this->assertTrue(new Reading(['user_id' => 7])->isOwnedBy(7));
    }

    public function testTagsArriveAsJsonOrAList(): void
    {
        $this->assertSame(['career', 'love'], new Reading(['tags' => '["career","love"]'])->tags);
        $this->assertSame([], new Reading(['tags' => '[]'])->tags);
        // Anything in the list that isn't a tag is dropped.
        $this->assertSame(['career'], new Reading(['tags' => '["career",3,null]'])->tags);
        $this->assertSame(['monthly'], new Reading()->setTags(['monthly'])->tags);
    }

    public function testTheJournalSettersSetWhatTheySay(): void
    {
        $reading = new Reading()
            ->setQuestion('What now?')
            ->setQuerent('Sam')
            ->setVisibility('password')
            ->setExpiresAt('2026-11-01T00:00:00Z');

        $this->assertSame(
            ['What now?', 'Sam', 'password', '2026-11-01T00:00:00Z'],
            [$reading->question, $reading->querent, $reading->visibility, $reading->expires_at]
        );
    }
}
