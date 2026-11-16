using System.Numerics;

namespace TarotGen.Plugin.Core;

/// <summary>A card's four corners on screen, clockwise from what is its top-left when upright.</summary>
public readonly record struct CardCorners(Vector2 TopLeft, Vector2 TopRight, Vector2 BottomRight, Vector2 BottomLeft);

/// <summary>
/// The geometry of a card turned on the canvas: where its corners land, which part
/// of its image each corner shows, and whether a click falls on it.
/// </summary>
public static class SpreadGeometry
{
    /// <summary>The corners of a <paramref name="w"/>×<paramref name="h"/> card centred at
    /// <paramref name="center"/>, turned <paramref name="rotationDeg"/> degrees clockwise
    /// (screen y points down).</summary>
    public static CardCorners Corners(Vector2 center, float w, float h, int rotationDeg)
    {
        var rad = rotationDeg * MathF.PI / 180f;
        float cos = MathF.Cos(rad), sin = MathF.Sin(rad);
        float hw = w / 2f, hh = h / 2f;

        Vector2 Rot(float lx, float ly) => center + new Vector2((lx * cos) - (ly * sin), (lx * sin) + (ly * cos));

        return new CardCorners(Rot(-hw, -hh), Rot(hw, -hh), Rot(hw, hh), Rot(-hw, hh));
    }

    /// <summary>The image coordinates for each corner. A reversed card is the image
    /// turned 180°, both axes flipped.</summary>
    public static CardCorners FaceUvs(bool reversed) => reversed
        ? new CardCorners(new Vector2(1, 1), new Vector2(0, 1), new Vector2(0, 0), new Vector2(1, 0))
        : new CardCorners(new Vector2(0, 0), new Vector2(1, 0), new Vector2(1, 1), new Vector2(0, 1));

    /// <summary>Whether a point falls inside a turned card centred at <paramref name="center"/>.</summary>
    public static bool HitTest(Vector2 point, Vector2 center, float w, float h, int rotationDeg)
    {
        var rad = -rotationDeg * MathF.PI / 180f;
        float cos = MathF.Cos(rad), sin = MathF.Sin(rad);
        float dx = point.X - center.X, dy = point.Y - center.Y;
        var lx = (dx * cos) - (dy * sin);
        var ly = (dx * sin) + (dy * cos);
        return MathF.Abs(lx) <= w / 2f && MathF.Abs(ly) <= h / 2f;
    }
}
