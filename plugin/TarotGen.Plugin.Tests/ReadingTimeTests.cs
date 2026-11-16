using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

public class ReadingTimeTests
{
    // Fourteen hours ahead of UTC, so a time read as local or as UTC by mistake shows.
    private static readonly TimeZoneInfo FarAhead = TimeZoneInfo.CreateCustomTimeZone("UTC+14", TimeSpan.FromHours(14), "UTC+14", "UTC+14");

    [Fact]
    public void ATimestampIsShownInTheGivenZone()
    {
        Assert.Equal("2026-11-07 23:18", ReadingFormat.Time("2026-11-07T09:18:09Z", FarAhead));
        Assert.Equal("2026-11-07 09:18", ReadingFormat.Time("2026-11-07T09:18:09Z", TimeZoneInfo.Utc));
    }

    [Fact]
    public void AnOlderServersSpaceFormIsReadAsUtc()
    {
        Assert.Equal("2026-11-07 23:18", ReadingFormat.Time("2026-11-07 09:18:09", FarAhead));
    }

    [Fact]
    public void ADayBoundaryMovesWithTheZone()
    {
        Assert.Equal("2026-11-08 13:30", ReadingFormat.Time("2026-11-07T23:30:00Z", FarAhead));
    }

    [Fact]
    public void NothingOrUnreadableTextIsLeftAsItIs()
    {
        Assert.Equal(string.Empty, ReadingFormat.Time(null, FarAhead));
        Assert.Equal(string.Empty, ReadingFormat.Time("  ", FarAhead));
        Assert.Equal("yesterday", ReadingFormat.Time("yesterday", FarAhead));
    }
}
