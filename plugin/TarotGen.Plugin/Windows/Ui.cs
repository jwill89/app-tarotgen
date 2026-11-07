using System.Numerics;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface;
using Dalamud.Interface.Components;
using Dalamud.Interface.Utility.Raii;

namespace TarotGen.Plugin.Windows;

/// <summary>
/// Shared ImGui building blocks so every tab uses one visual language: icon +
/// accent section headers, a three-tier button system, and a consistent
/// label/help hierarchy. Icons come from Dalamud's bundled FontAwesome font.
/// </summary>
internal static class Ui
{
    // ── palette (used sparingly + semantically) ────────────────────────────────
    public static readonly Vector4 SectionColor = new(0.85f, 0.75f, 0.5f, 1f);  // gold: section + highlight
    public static readonly Vector4 AccentColor = new(0.72f, 0.62f, 0.9f, 1f);   // lavender: incidental accents
    public static readonly Vector4 SuccessColor = new(0.4f, 0.85f, 0.4f, 1f);   // green: connected / saved
    public static readonly Vector4 WarnColor = new(1f, 0.82f, 0.4f, 1f);        // amber: attention

    private static readonly Vector4 Primary = new(0.46f, 0.33f, 0.66f, 1f);
    private static readonly Vector4 PrimaryHover = new(0.56f, 0.43f, 0.76f, 1f);
    private static readonly Vector4 PrimaryActive = new(0.38f, 0.26f, 0.56f, 1f);

    private static readonly Vector4 Danger = new(0.60f, 0.18f, 0.20f, 1f);
    private static readonly Vector4 DangerHover = new(0.74f, 0.24f, 0.26f, 1f);
    private static readonly Vector4 DangerActive = new(0.50f, 0.14f, 0.16f, 1f);

    // ── icons ──────────────────────────────────────────────────────────────────

    /// <summary>Render a FontAwesome glyph inline (default text colour).</summary>
    public static void Icon(FontAwesomeIcon icon)
    {
        using (ImRaii.PushFont(UiBuilder.IconFont))
            ImGui.TextUnformatted(icon.ToIconString());
    }

    /// <summary>Render a FontAwesome glyph inline in <paramref name="color"/>.</summary>
    public static void Icon(FontAwesomeIcon icon, Vector4 color)
    {
        using (ImRaii.PushColor(ImGuiCol.Text, color))
        using (ImRaii.PushFont(UiBuilder.IconFont))
            ImGui.TextUnformatted(icon.ToIconString());
    }

    // ── section headers ──────────────────────────────────────────────────────────

    /// <summary>A non-collapsible section header: icon + gold label + rule.</summary>
    public static void Section(FontAwesomeIcon icon, string label)
    {
        ImGui.Spacing();
        Icon(icon, SectionColor);
        ImGui.SameLine(0, ImGui.GetStyle().ItemInnerSpacing.X * 1.5f);
        ImGui.TextColored(SectionColor, label);
        ImGui.Separator();
    }

    /// <summary>
    /// A collapsible section header (icon + gold label). Returns whether it is open;
    /// wrap the body in <see cref="Body"/> so it reads as belonging to the section.
    /// </summary>
    public static bool CollapsingSection(FontAwesomeIcon icon, string label, bool defaultOpen = true)
    {
        ImGui.Spacing();
        ImGui.AlignTextToFramePadding();
        Icon(icon, SectionColor);
        ImGui.SameLine(0, ImGui.GetStyle().ItemInnerSpacing.X * 1.5f);

        using var col = ImRaii.PushColor(ImGuiCol.Text, SectionColor);
        var flags = defaultOpen ? ImGuiTreeNodeFlags.DefaultOpen : ImGuiTreeNodeFlags.None;
        return ImGui.CollapsingHeader(label, flags);
    }

    /// <summary>Indent a section's body so grouped controls read as one section.</summary>
    public static IDisposable Body() => ImRaii.PushIndent();

    // ── text hierarchy ───────────────────────────────────────────────────────────

    /// <summary>A field label — normal (bright) text, brighter than its help.</summary>
    public static void Label(string text) => ImGui.TextUnformatted(text);

    /// <summary>Secondary/help text — dimmed.</summary>
    public static void Help(string text) => ImGui.TextDisabled(text);

    /// <summary>Secondary text, dimmed and wrapped at the edge of its column.</summary>
    public static void HelpWrapped(string text)
    {
        ImGui.PushTextWrapPos(0f);
        ImGui.TextDisabled(text);
        ImGui.PopTextWrapPos();
    }

    /// <summary>A dimmed "(?)" that shows <paramref name="text"/> as a wrapped tooltip on hover.</summary>
    public static void HelpMarker(string text)
    {
        ImGui.TextDisabled("(?)");
        if (!ImGui.IsItemHovered())
            return;

        ImGui.BeginTooltip();
        ImGui.PushTextWrapPos(ImGui.GetFontSize() * 24f);
        ImGui.TextUnformatted(text);
        ImGui.PopTextWrapPos();
        ImGui.EndTooltip();
    }

    // ── buttons (three tiers) ──────────────────────────────────────────────────────

    /// <summary>Primary call-to-action — filled brand colour.</summary>
    public static bool PrimaryButton(string label, Vector2 size = default)
    {
        using (ImRaii.PushColor(ImGuiCol.Button, Primary))
        using (ImRaii.PushColor(ImGuiCol.ButtonHovered, PrimaryHover))
        using (ImRaii.PushColor(ImGuiCol.ButtonActive, PrimaryActive))
            return ImGui.Button(label, size);
    }

    /// <summary>Secondary action — bordered so it reads clearly as a button.</summary>
    public static bool Button(string label, Vector2 size = default)
    {
        using (ImRaii.PushStyle(ImGuiStyleVar.FrameBorderSize, 1f))
            return ImGui.Button(label, size);
    }

    /// <summary>Destructive / caution action — red + bordered.</summary>
    public static bool DangerButton(string label, Vector2 size = default)
    {
        using (ImRaii.PushStyle(ImGuiStyleVar.FrameBorderSize, 1f))
        using (ImRaii.PushColor(ImGuiCol.Button, Danger))
        using (ImRaii.PushColor(ImGuiCol.ButtonHovered, DangerHover))
        using (ImRaii.PushColor(ImGuiCol.ButtonActive, DangerActive))
            return ImGui.Button(label, size);
    }

    /// <summary>A compact icon button with a hover tooltip (for toolbars).</summary>
    public static bool IconButton(string id, FontAwesomeIcon icon, string tooltip)
    {
        using var _ = ImRaii.PushId(id);
        var clicked = ImGuiComponents.IconButton(icon);
        if (!string.IsNullOrEmpty(tooltip) && ImGui.IsItemHovered())
            ImGui.SetTooltip(tooltip);
        return clicked;
    }

    /// <summary>A link-coloured button (icon + text) for a coloured accent link.</summary>
    public static bool ColorButton(string label, Vector4 color, Vector4 hover, Vector4 active)
    {
        using (ImRaii.PushStyle(ImGuiStyleVar.FrameBorderSize, 1f))
        using (ImRaii.PushColor(ImGuiCol.Button, color))
        using (ImRaii.PushColor(ImGuiCol.ButtonHovered, hover))
        using (ImRaii.PushColor(ImGuiCol.ButtonActive, active))
            return ImGui.Button(label);
    }
}
