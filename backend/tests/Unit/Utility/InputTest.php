<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Exception\ApiException;
use Tarot\Utility\Input;

/**
 * Locks in the coercion rules for untrusted request input: the stringy-boolean
 * handling and the trimmed text fields whose length limit refuses, never cuts.
 */
#[CoversClass(Input::class)]
final class InputTest extends TestCase
{
    /**
     * @return array<string,array{mixed,bool}>
     */
    public static function boolCases(): array
    {
        return [
            'true bool'      => [true, true],
            'false bool'     => [false, false],
            'string true'    => ['true', true],
            'string false'   => ['false', false],
            'string one'     => ['1', true],
            'string zero'    => ['0', false],
            'on'             => ['on', true],
            'off'            => ['off', false],
            'empty string'   => ['', false],
            'int one'        => [1, true],
            'int zero'       => [0, false],
        ];
    }

    #[DataProvider('boolCases')]
    public function testBoolCoercesLikeFilterVar(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Input::bool($value));
    }

    public function testBoolMissingValueUsesDefault(): void
    {
        self::assertFalse(Input::bool(null));
        self::assertTrue(Input::bool(null, true));
    }

    public function testTextTrimsAndCasts(): void
    {
        self::assertSame('hello', Input::text('  hello  ', 100, 'body.name'));
        self::assertSame('42', Input::text(42, 100, 'body.name'));
        self::assertSame('', Input::text(null, 100, 'body.name'));
        self::assertSame('', Input::text(['not', 'text'], 100, 'body.name'));
    }

    public function testTextUpToTheLimitIsKept(): void
    {
        // Counted in characters, not bytes.
        self::assertSame('héllo', Input::text('héllo', 5, 'body.name'));
        self::assertSame('abcde', Input::text('  abcde  ', 5, 'body.name'), 'trimmed before it is measured');
    }

    public function testTextOverTheLimitIsRefusedNotCut(): void
    {
        try {
            Input::text('héllo wörld', 5, 'body.reading_name');
            self::fail('A value over the limit was accepted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('body.reading_name', $e->getLocation());
            self::assertSame('reading_name can be at most 5 characters.', $e->getMessage());
        }
    }

    public function testTextCanSkipTrim(): void
    {
        self::assertSame('  hi  ', Input::text('  hi  ', 100, 'body.notes', trim: false));
    }

    public function testNullableTextReturnsNullWhenEmptyAfterTrim(): void
    {
        self::assertNull(Input::nullableText('   ', 100, 'body.name'));
        self::assertNull(Input::nullableText(null, 100, 'body.name'));
        self::assertNull(Input::nullableText('', 100, 'body.name'));
    }

    public function testNullableTextPreservesUntrimmedValueWhenTrimDisabled(): void
    {
        // Whitespace-only is still null, but a real value keeps its inner spacing.
        self::assertNull(Input::nullableText('   ', 100, 'body.notes', trim: false));
        self::assertSame(' keep me ', Input::nullableText(' keep me ', 100, 'body.notes', trim: false));
    }
}
