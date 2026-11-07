using System.Globalization;
using System.Numerics;
using Dalamud.Bindings.ImGui;

namespace TarotGen.Plugin.Services;

/// <summary>Low-level ImGui draw-list helpers shared by the reading render and the
/// new-reading spread preview: rotated card quads/outlines and order badges.</summary>
public static class SpreadDraw
{
    /// <summary>Draw a card texture as a rotated quad centred at <paramref name="center"/>.
    /// A reversed card is the image turned 180° (both UV axes flipped).</summary>
    public static void RotatedImage(ImDrawListPtr dl, ImTextureID tex, Vector2 center, float w, float h, int rotationDeg, bool reversed)
    {
        var (p1, p2, p3, p4) = Corners(center, w, h, rotationDeg);

        Vector2 uv1, uv2, uv3, uv4;
        if (reversed)
        {
            uv1 = new Vector2(1, 1); uv2 = new Vector2(0, 1); uv3 = new Vector2(0, 0); uv4 = new Vector2(1, 0);
        }
        else
        {
            uv1 = new Vector2(0, 0); uv2 = new Vector2(1, 0); uv3 = new Vector2(1, 1); uv4 = new Vector2(0, 1);
        }

        dl.AddImageQuad(tex, p1, p2, p3, p4, uv1, uv2, uv3, uv4, 0xFFFFFFFFu);
    }

    /// <summary>Draw a rotated rectangle outline (a card slot, for the spread preview).</summary>
    public static void RotatedRect(ImDrawListPtr dl, Vector2 center, float w, float h, int rotationDeg, uint col, float thickness = 1.5f)
    {
        var (p1, p2, p3, p4) = Corners(center, w, h, rotationDeg);
        dl.AddQuad(p1, p2, p3, p4, col, thickness);
    }

    /// <summary>A filled order badge with the position number, drawn on top of cards.</summary>
    public static void OrderBadge(ImDrawListPtr dl, Vector2 center, int order, float scale)
    {
        var r = 9f * scale;
        dl.AddCircleFilled(center, r, 0xC8000000u);
        dl.AddCircle(center, r, 0x66FFFFFFu);
        OrderNumber(dl, center, order);
    }

    /// <summary>Just the position number, centred at <paramref name="center"/>.</summary>
    public static void OrderNumber(ImDrawListPtr dl, Vector2 center, int order)
    {
        var text = order.ToString(CultureInfo.InvariantCulture);
        var ts = ImGui.CalcTextSize(text);
        dl.AddText(center - (ts / 2f), 0xFFFFFFFFu, text);
    }

    /// <summary>Whether a point falls inside a rotated card rectangle centred at <paramref name="center"/>.</summary>
    public static bool HitTest(Vector2 point, Vector2 center, float w, float h, int rotationDeg)
    {
        var rad = -rotationDeg * MathF.PI / 180f;
        float cos = MathF.Cos(rad), sin = MathF.Sin(rad);
        float dx = point.X - center.X, dy = point.Y - center.Y;
        var lx = (dx * cos) - (dy * sin);
        var ly = (dx * sin) + (dy * cos);
        return MathF.Abs(lx) <= w / 2f && MathF.Abs(ly) <= h / 2f;
    }

    private static (Vector2, Vector2, Vector2, Vector2) Corners(Vector2 center, float w, float h, int rotationDeg)
    {
        var rad = rotationDeg * MathF.PI / 180f;
        float cos = MathF.Cos(rad), sin = MathF.Sin(rad);
        float hw = w / 2f, hh = h / 2f;

        Vector2 Rot(float lx, float ly) => center + new Vector2((lx * cos) - (ly * sin), (lx * sin) + (ly * cos));

        return (Rot(-hw, -hh), Rot(hw, -hh), Rot(hw, hh), Rot(-hw, hh));
    }
}
