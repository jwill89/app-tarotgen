<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Data\ReadingFilter;
use Tarot\Exception\ApiException;
use Tarot\Service\ReadingInput;

/**
 * The rules for what a reader sends about a reading: the journal fields, tags,
 * who can open it, a guest's expiry and My Readings' filters.
 */
#[CoversClass(ReadingInput::class)]
#[CoversClass(ReadingFilter::class)]
final class ReadingInputTest extends TestCase
{
    public function testTheQuestionAndQuerentAreTrimmedCappedOrNull(): void
    {
        $this->assertSame('What now?', ReadingInput::question('  What now?  '));
        $this->assertNull(ReadingInput::question('   '));
        $this->assertNull(ReadingInput::question(['not', 'text']));
        $this->assertSame(500, mb_strlen((string) ReadingInput::question(str_repeat('ä', 501))));
        $this->assertSame('Sam', ReadingInput::querent(' Sam '));
        $this->assertSame('42', ReadingInput::querent(42));
        $this->assertSame(100, mb_strlen((string) ReadingInput::querent(str_repeat('x', 101))));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function tagSpellings(): iterable
    {
        yield 'lower-cased' => ['Career', 'career'];
        yield 'beyond ASCII, lower-cased not folded' => ['STRAẞE', 'straße'];
        yield 'trimmed, inner whitespace one space' => ["  new \t  moon\n", 'new moon'];
        yield 'a leading # dropped' => ['##Monthly', 'monthly'];
        yield 'only a leading #' => ['c#', 'c#'];
        yield 'control characters' => ["love\u{0007}life", 'love life'];
        yield 'an emoji held together by a zero-width joiner' => ["\u{1F469}\u{200D}\u{1F467}", "\u{1F469}\u{200D}\u{1F467}"];
        yield 'nothing but a #' => ['#', ''];
    }

    #[DataProvider('tagSpellings')]
    public function testATagIsNormalised(string $raw, string $tag): void
    {
        $this->assertSame($tag, ReadingInput::tag($raw));
    }

    public function testTagsComeAsAListOrOneCommaSeparatedString(): void
    {
        $this->assertSame([], ReadingInput::tags(null));
        $this->assertSame([], ReadingInput::tags(''));
        $this->assertSame(['love', 'career'], ReadingInput::tags('Love, career,, ,LOVE'));
        $this->assertSame(['work', 'home', 'travel'], ReadingInput::tags(['Work', 'home, travel', '#work']));
        // A number-like tag stays text.
        $this->assertSame(['2026'], ReadingInput::tags(['2026']));
    }

    /**
     * @return iterable<string,array{mixed,string}>
     */
    public static function badTags(): iterable
    {
        yield 'eleven' => [array_map(static fn(int $n): string => "t{$n}", range(1, 11)), 'A reading can have at most 10 tags.'];
        yield 'eleven in one string' => [implode(',', range(1, 11)), 'A reading can have at most 10 tags.'];
        yield 'too long' => [[str_repeat('a', 31)], 'A tag can be at most 30 characters: "' . str_repeat('a', 31) . '".'];
        yield 'not text' => [[1, 2], 'Tags must be text.'];
        yield 'a number' => [7, 'Tags must be text.'];
    }

    #[DataProvider('badTags')]
    public function testTagsOverTheLimitsAreRefused(mixed $tags, string $error): void
    {
        $this->expectExceptionObject(new ApiException($error, 400));
        ReadingInput::tags($tags);
    }

    public function testAGuestMayChooseOneSevenOrThirtyDays(): void
    {
        $this->assertNull(ReadingInput::expiresAt(null));
        $this->assertNull(ReadingInput::expiresAt(''));
        foreach ([1, 7, 30, '30'] as $days) {
            $expires = ReadingInput::expiresAt($days);
            $this->assertIsString($expires);
            $this->assertEqualsWithDelta(time() + (int) $days * 86400, strtotime($expires . ' UTC'), 5, (string) $days);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires);
        }

        foreach ([2, '7 ', 365, true, [7]] as $days) {
            try {
                ReadingInput::expiresAt($days);
                $this->fail('Accepted ' . json_encode($days));
            } catch (ApiException $e) {
                $this->assertSame(400, $e->getStatusCode());
            }
        }
    }

    public function testVisibilityIsOneOfThree(): void
    {
        $this->assertNull(ReadingInput::visibility(null));
        $this->assertNull(ReadingInput::visibility(''));
        $this->assertSame('private', ReadingInput::visibility('private'));

        $this->expectExceptionObject(new ApiException('Choose who can open the reading: link, password or private.', 400));
        ReadingInput::visibility('Private');
    }

    public function testPrivateAndLinkBothDropAnyPassword(): void
    {
        $this->assertSame(['is_private' => true, 'password_hash' => null], ReadingInput::access(['visibility' => 'private', 'password' => 'abcd']));
        $this->assertSame(['is_private' => false, 'password_hash' => null], ReadingInput::access(['visibility' => 'link'], true));
    }

    public function testPasswordSetsANewOneOrKeepsTheOneThereIs(): void
    {
        $set = ReadingInput::access(['visibility' => 'password', 'password' => 'abcd']);
        $this->assertFalse($set['is_private'] ?? null);
        $this->assertTrue(password_verify('abcd', $set['password_hash'] ?? ''));

        $this->assertSame(['is_private' => false], ReadingInput::access(['visibility' => 'password'], true));

        try {
            ReadingInput::access(['visibility' => 'password']);
            $this->fail('Password with no password was accepted.');
        } catch (ApiException $e) {
            $this->assertSame([400, 'Choose a password of at least 4 characters for this reading.'], [$e->getStatusCode(), $e->getMessage()]);
        }

        // The edit endpoint answers a password problem with its own status.
        $this->expectExceptionObject(new ApiException('Reading password must be at least 4 characters.', 422));
        ReadingInput::access(['visibility' => 'password', 'password' => 'abc'], false, 422);
    }

    public function testWithoutVisibilityTheOlderFieldsWorkAsBefore(): void
    {
        $this->assertSame([], ReadingInput::access([]));
        $this->assertSame([], ReadingInput::access(['password' => '']));
        // Removing wins over a password sent with it.
        $this->assertSame(['password_hash' => null], ReadingInput::access(['remove_password' => 'true', 'password' => 'abcd']));
        // A password alone makes it a password reading.
        $legacy = ReadingInput::access(['password' => 'abcd']);
        $this->assertFalse($legacy['is_private'] ?? null);
        $this->assertArrayHasKey('password_hash', $legacy);
    }

    public function testNoFiltersIsAnEmptyFilter(): void
    {
        $this->assertEquals(new ReadingFilter(), ReadingInput::filter([]));
        $this->assertEquals(new ReadingFilter(), ReadingInput::filter(['q' => ' ', 'deck_id' => '', 'tag' => '#', 'tz' => '']));
    }

    public function testFiltersAreNormalised(): void
    {
        $filter = ReadingInput::filter([
            'q' => '  ' . str_repeat('x', 120), 'deck_id' => '12', 'spread' => ' Celtic Cross ', 'tag' => ' #Career ', 'daily' => 'exclude',
        ]);

        $this->assertSame(100, mb_strlen((string) $filter->text));
        $this->assertSame(12, $filter->deckId);
        $this->assertSame('Celtic Cross', $filter->spread);
        $this->assertSame('career', $filter->tag);
        $this->assertFalse($filter->daily);
        $this->assertTrue(ReadingInput::filter(['daily' => 'only'])->daily);
    }

    public function testTheReadersDatesBecomeUtcBoundsTheLastDayIncluded(): void
    {
        $utc = ReadingInput::filter(['from' => '2026-03-01', 'to' => '2026-03-01']);
        $this->assertSame(['2026-03-01 00:00:00', '2026-03-02 00:00:00'], [$utc->from, $utc->until]);

        // New York, across the start of daylight saving time (8 March 2026): the
        // first day starts at UTC−5, the day after the last at UTC−4.
        $ny = ReadingInput::filter(['from' => '2026-03-07', 'to' => '2026-03-08', 'tz' => 'America/New_York']);
        $this->assertSame(['2026-03-07 05:00:00', '2026-03-09 04:00:00'], [$ny->from, $ny->until]);

        $this->assertSame('2026-02-28 11:00:00', ReadingInput::filter(['from' => '2026-03-01', 'tz' => 'Pacific/Auckland'])->from);
    }
}
