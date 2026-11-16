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
    public function testTheQuestionAndQuerentAreTrimmedOrNull(): void
    {
        $this->assertSame('What now?', ReadingInput::question('  What now?  '));
        $this->assertNull(ReadingInput::question('   '));
        $this->assertNull(ReadingInput::question(['not', 'text']));
        $this->assertSame(500, mb_strlen((string) ReadingInput::question(str_repeat('ä', 500))));
        $this->assertSame('Sam', ReadingInput::querent(' Sam '));
        $this->assertSame('42', ReadingInput::querent(42));
        $this->assertSame(100, mb_strlen((string) ReadingInput::querent(str_repeat('x', 100))));
    }

    public function testTheNameNotesQuestionAndQuerentOverTheirLimitsAreRefusedNotCut(): void
    {
        $cases = [
            'body.reading_name'  => static fn() => ReadingInput::name(str_repeat('x', ReadingInput::NAME_MAX + 1)),
            'body.reading_notes' => static fn() => ReadingInput::notes(str_repeat('x', ReadingInput::NOTES_MAX + 1)),
            'body.question'      => static fn() => ReadingInput::question(str_repeat('ä', ReadingInput::QUESTION_MAX + 1)),
            'body.querent'       => static fn() => ReadingInput::querent(str_repeat('x', ReadingInput::QUERENT_MAX + 1)),
        ];
        foreach ($cases as $location => $call) {
            try {
                $call();
                $this->fail("{$location} over its limit was accepted.");
            } catch (ApiException $e) {
                $this->assertSame([422, $location], [$e->getStatusCode(), $e->getLocation()]);
            }
        }

        // Notes keep their inner spacing; only the empty check is trimmed.
        $this->assertSame(' a  b ', ReadingInput::notes(' a  b '));
        $this->assertNull(ReadingInput::notes('   '));
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
        $this->expectExceptionObject(new ApiException($error, 422, location: 'body.tags'));
        ReadingInput::tags($tags);
    }

    public function testAGuestMayChooseOneSevenOrThirtyDays(): void
    {
        $this->assertNull(ReadingInput::expiresAt(null));
        $this->assertNull(ReadingInput::expiresAt(''));
        foreach ([1, 7, 30, '30'] as $days) {
            $expires = ReadingInput::expiresAt($days);
            $this->assertIsString($expires);
            $this->assertEqualsWithDelta(time() + (int) $days * 86400, strtotime($expires), 5, (string) $days);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $expires);
        }

        foreach ([2, '7 ', 365, true, [7]] as $days) {
            try {
                ReadingInput::expiresAt($days);
                $this->fail('Accepted ' . json_encode($days));
            } catch (ApiException $e) {
                $this->assertSame([422, 'body.expires_in_days'], [$e->getStatusCode(), $e->getLocation()]);
            }
        }
    }

    public function testVisibilityIsOneOfThree(): void
    {
        $this->assertNull(ReadingInput::visibility(null));
        $this->assertNull(ReadingInput::visibility(''));
        $this->assertSame('private', ReadingInput::visibility('private'));

        $this->expectExceptionObject(new ApiException('Choose who can open the reading: link, password or private.', 422, location: 'body.visibility'));
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
            $this->assertSame(
                [422, 'body.password', 'Choose a password of at least 4 characters for this reading.'],
                [$e->getStatusCode(), $e->getLocation(), $e->getMessage()]
            );
        }

        $this->expectExceptionObject(new ApiException('Reading password must be at least 4 characters.', 422, location: 'body.password'));
        ReadingInput::access(['visibility' => 'password', 'password' => 'abc']);
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
            'q' => '  ' . str_repeat('x', 100), 'deck_id' => '12', 'spread' => ' Celtic Cross ', 'tag' => ' #Career ', 'daily' => 'exclude',
        ]);

        $this->assertSame(100, mb_strlen((string) $filter->text));
        $this->assertSame(12, $filter->deckId);
        $this->assertSame('Celtic Cross', $filter->spread);
        $this->assertSame('career', $filter->tag);
        $this->assertFalse($filter->daily);
        $this->assertTrue(ReadingInput::filter(['daily' => 'only'])->daily);
    }

    public function testASearchOrSpreadOverItsLimitIsRefusedNotCut(): void
    {
        foreach (['q' => 101, 'spread' => 201] as $name => $length) {
            try {
                ReadingInput::filter([$name => str_repeat('x', $length)]);
                $this->fail("A {$name} of {$length} characters was accepted.");
            } catch (ApiException $e) {
                $this->assertSame([422, "query.{$name}"], [$e->getStatusCode(), $e->getLocation()]);
            }
        }
    }

    public function testTheReadersDatesBecomeUtcBoundsTheLastDayIncluded(): void
    {
        $utc = ReadingInput::filter(['from' => '2026-03-01', 'to' => '2026-03-01']);
        $this->assertSame(['2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z'], [$utc->from, $utc->until]);

        // New York, across the start of daylight saving time (8 March 2026): the
        // first day starts at UTC−5, the day after the last at UTC−4.
        $ny = ReadingInput::filter(['from' => '2026-03-07', 'to' => '2026-03-08', 'tz' => 'America/New_York']);
        $this->assertSame(['2026-03-07T05:00:00Z', '2026-03-09T04:00:00Z'], [$ny->from, $ny->until]);

        $this->assertSame('2026-02-28T11:00:00Z', ReadingInput::filter(['from' => '2026-03-01', 'tz' => 'Pacific/Auckland'])->from);
    }
}
