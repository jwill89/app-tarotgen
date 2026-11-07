using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Services;

/// <summary>Display formatting shared across the plugin's reading views.</summary>
public static class ReadingFormat
{
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
}
