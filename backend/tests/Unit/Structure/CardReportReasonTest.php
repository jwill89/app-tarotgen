<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Structure\CardReportReason;

/**
 * The report reasons exist in three places by design. The website's copy is
 * typed from the generated spec, so the compiler keeps it in step; the
 * plugin's C# copy has nothing like that, so this reads it. A reason added here
 * that the plugin lacks is one it can't offer; one only the plugin has is a 400.
 */
#[CoversClass(CardReportReason::class)]
final class CardReportReasonTest extends TestCase
{
    public function testThePluginOffersExactlyTheseReasons(): void
    {
        $source = dirname(__DIR__, 4) . '/plugin/TarotGen.Plugin/Models/ApiModels.cs';
        if (!is_file($source)) {
            self::markTestSkipped('The plugin source is not checked out.');
        }

        $code = (string) file_get_contents($source);
        $this->assertSame(1, preg_match('/IReadOnlyList<CardReportReason> All =\s*\[(.*?)\];/s', $code, $list));
        preg_match_all('/new\("([a-z_]+)"/', $list[1], $keys);

        $this->assertEqualsCanonicalizing(
            array_map(static fn(CardReportReason $reason): string => $reason->value, CardReportReason::cases()),
            $keys[1]
        );
    }
}
