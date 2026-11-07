using System.Numerics;
using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Geometry for laying a spread's positions onto a square canvas — a C# port of
/// the web app's <c>useSpreadLayout</c> so the plugin renders spreads identically.
/// Positions are in 0–100 canvas-percent; a card is a fixed fraction of the
/// canvas width with a tarot aspect ratio.
/// </summary>
public static class SpreadLayout
{
    public const float CardWidthPct = 11f;
    public const float CardAspect = 8.6f / 5f; // height / width

    public readonly record struct Fit(float Scale, float Cx, float Cy);

    /// <summary>
    /// Zoom the layout so cards fill the canvas instead of leaving dead space
    /// around small spreads: bound all positions (plus card extents) and scale to
    /// fit, clamped to [1, 2.5].
    /// </summary>
    public static Fit ComputeFit(IReadOnlyList<SpreadPosition> positions)
    {
        if (positions.Count == 0)
            return new Fit(1f, 50f, 50f);

        const float halfW = CardWidthPct / 2f;
        const float halfH = CardWidthPct * CardAspect / 2f;

        float minX = float.MaxValue, maxX = float.MinValue, minY = float.MaxValue, maxY = float.MinValue;
        foreach (var p in positions)
        {
            minX = Math.Min(minX, (float)p.X);
            maxX = Math.Max(maxX, (float)p.X);
            minY = Math.Min(minY, (float)p.Y);
            maxY = Math.Max(maxY, (float)p.Y);
        }

        var bboxW = maxX - minX + halfW * 2f;
        var bboxH = maxY - minY + halfH * 2f;
        var scale = Math.Max(1f, Math.Min(100f / bboxW, 100f / bboxH) * 0.92f);

        return new Fit(Math.Min(scale, 2.5f), (minX + maxX) / 2f, (minY + maxY) / 2f);
    }

    /// <summary>A card's size in canvas-percent {w, h} at the given fit.</summary>
    public static Vector2 CardSizePct(Fit fit)
    {
        var w = CardWidthPct * fit.Scale;
        return new Vector2(w, w * CardAspect);
    }

    /// <summary>Project a position to its on-canvas centre (canvas-percent, 0–100).</summary>
    public static Vector2 Project(Fit fit, double x, double y)
        => new(50f + ((float)x - fit.Cx) * fit.Scale, 50f + ((float)y - fit.Cy) * fit.Scale);
}
