<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Exception\ApiException;
use Tarot\Service\SpreadInput;

#[CoversClass(SpreadInput::class)]
final class SpreadInputTest extends TestCase
{
    public function testPositionsWithinTheLimitsPass(): void
    {
        SpreadInput::positions([
            ['order' => 1, 'title' => str_repeat('t', SpreadInput::TITLE_MAX), 'x' => 0, 'y' => 100],
            ['order' => 2, 'description' => str_repeat('d', SpreadInput::DESCRIPTION_MAX), 'x' => '12.5'],
            ['order' => 3],
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string,array{array<mixed>,string,string}>
     */
    public static function positionsPastALimit(): iterable
    {
        yield 'not an object' => [[['order' => 1], 'text'], 'body.positions[1]', 'Invalid position data.'];
        yield 'off the canvas' => [[['x' => 100.01]], 'body.positions[0].x', 'x must be a number from 0 to 100.'];
        yield 'not a number' => [[['y' => 'top']], 'body.positions[0].y', 'y must be a number from 0 to 100.'];
        yield 'a long title' => [[['title' => str_repeat('t', 101)]], 'body.positions[0].title', 'title can be at most 100 characters.'];
        yield 'a long description' => [[['description' => str_repeat('d', 281)]], 'body.positions[0].description', 'description can be at most 280 characters.'];
    }

    /** @param array<mixed> $positions */
    #[DataProvider('positionsPastALimit')]
    public function testAPositionPastALimitIsRefusedAtItsLocation(array $positions, string $location, string $message): void
    {
        try {
            SpreadInput::positions($positions);
            $this->fail('A position past a limit was accepted.');
        } catch (ApiException $e) {
            $this->assertSame([422, $location, $message], [$e->getStatusCode(), $e->getLocation(), $e->getMessage()]);
        }
    }

    public function testACoordinateIsRoundedAndMissingIsTheMiddle(): void
    {
        $this->assertSame(25.56, SpreadInput::coordinate(25.555, 'body.x'));
        $this->assertSame(50.0, SpreadInput::coordinate(null, 'body.x'));
        $this->assertSame(50.0, SpreadInput::coordinate('', 'body.x'));
        // Rounded onto the canvas edge is on it.
        $this->assertSame(100.0, SpreadInput::coordinate(100.004, 'body.x'));
    }

    public function testANameIsTrimmedAndRefusedOverItsLimit(): void
    {
        $this->assertSame('Crossroads', SpreadInput::name('  Crossroads  '));

        $this->expectExceptionObject(new ApiException('name can be at most 200 characters.', 422, location: 'body.name'));
        SpreadInput::name(str_repeat('n', SpreadInput::NAME_MAX + 1));
    }
}
