using System.Globalization;

namespace TarotGen.Plugin.Core;

/// <summary>Display formatting shared across the plugin's reading views.</summary>
public static class ReadingFormat
{
    /// <summary>
    /// A timestamp the site sent (RFC 3339 in UTC, <c>2026-11-07T09:18:09Z</c>, or an older
    /// server's <c>2026-11-07 09:18:09</c>, also UTC) as <c>yyyy-MM-dd HH:mm</c> in
    /// <paramref name="zone"/>; text that is neither comes back as it was.
    /// </summary>
    public static string Time(string? stamp, TimeZoneInfo zone)
    {
        if (string.IsNullOrWhiteSpace(stamp))
            return string.Empty;

        if (!DateTimeOffset.TryParse(stamp, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var at))
            return stamp;

        return TimeZoneInfo.ConvertTime(at, zone).ToString("yyyy-MM-dd HH:mm", CultureInfo.InvariantCulture);
    }

    /// <summary><see cref="Time(string?, TimeZoneInfo)"/> in this machine's zone.</summary>
    public static string Time(string? stamp) => Time(stamp, TimeZoneInfo.Local);

    /// <summary>
    /// A reading's display title, matching the website's reading list: the custom
    /// name if set, else the spread's name, else "Free Draw (N cards)".
    /// </summary>
    public static string Title(Reading reading)
    {
        if (!string.IsNullOrWhiteSpace(reading.ReadingName))
            return reading.ReadingName!;

        var info = reading.ReadingInfo;
        if (info?.Spread != null && !string.IsNullOrWhiteSpace(info.Spread.Name))
            return info.Spread.Name;

        var count = info?.Draw.Count ?? 0;
        return $"Free Draw ({count} card{(count == 1 ? string.Empty : "s")})";
    }

    /// <summary>
    /// Whether My Readings' filter keeps <paramref name="reading"/>: the query found,
    /// ignoring case, in its title, its id or its deck's name. An empty query keeps all.
    /// </summary>
    public static bool Matches(Reading reading, string deckName, string query)
    {
        query = query.Trim();
        if (query.Length == 0)
            return true;

        return Title(reading).Contains(query, StringComparison.OrdinalIgnoreCase)
            || reading.ReadingId.Contains(query, StringComparison.OrdinalIgnoreCase)
            || deckName.Contains(query, StringComparison.OrdinalIgnoreCase);
    }
}
