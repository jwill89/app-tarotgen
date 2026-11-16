using System.Globalization;
using System.Numerics;
using Dalamud.Bindings.ImGui;
using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Windows;

/// <summary>Low-level ImGui draw-list helpers shared by the reading render and the
/// new-reading spread preview: rotated card quads/outlines and order badges.</summary>
internal static class SpreadDraw
{
    /// <summary>Draw a card texture as a rotated quad centred at <paramref name="center"/>.</summary>
    public static void RotatedImage(ImDrawListPtr dl, ImTextureID tex, Vector2 center, float w, float h, int rotationDeg, bool reversed)
    {
        var at = SpreadGeometry.Corners(center, w, h, rotationDeg);
        var uv = SpreadGeometry.FaceUvs(reversed);
        dl.AddImageQuad(
            tex,
            at.TopLeft, at.TopRight, at.BottomRight, at.BottomLeft,
            uv.TopLeft, uv.TopRight, uv.BottomRight, uv.BottomLeft,
            0xFFFFFFFFu);
    }

    /// <summary>Draw a rotated rectangle outline (a card slot, for the spread preview).</summary>
    public static void RotatedRect(ImDrawListPtr dl, Vector2 center, float w, float h, int rotationDeg, uint col, float thickness = 1.5f)
    {
        var at = SpreadGeometry.Corners(center, w, h, rotationDeg);
        dl.AddQuad(at.TopLeft, at.TopRight, at.BottomRight, at.BottomLeft, col, thickness);
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
}
