using System.Numerics;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface;
using Dalamud.Interface.Utility;
using Dalamud.Interface.Utility.Raii;
using Dalamud.Interface.Windowing;
using Dalamud.Plugin;
using Dalamud.Utility;
using TarotGen.Plugin.Models;
using TarotGen.Plugin.Services;

namespace TarotGen.Plugin.Windows;

/// <summary>
/// The single plugin window. Everything lives here as tabs — New Reading, Reading
/// (hosted by <see cref="ReadingPanel"/>), My Readings, and Account.
/// </summary>
public sealed class MainWindow : Window, IDisposable
{
    private static readonly Vector4 ErrorColor = new(1f, 0.45f, 0.45f, 1f);

    private readonly TarotApiClient api;
    private readonly ReadingPanel readingPanel;
    private readonly LinkService linkService;
    private readonly DrawKeyService drawKeys;
    private readonly Configuration config;
    private readonly IDalamudPluginInterface pluginInterface;

    private bool onboardingShown;
    private bool drawKeyNoticeShown;

    private static readonly string PluginVersion =
        typeof(MainWindow).Assembly.GetName().Version?.ToString(3) ?? "0.0.0";

    private IReadOnlyList<Spread> spreads = Array.Empty<Spread>();
    private List<SpreadGroup> spreadGroups = new();
    private List<DeckGroup> deckGroups = new();
    private bool catalogRequested;
    private bool catalogLoading;
    private string? catalogError;

    private Deck? selectedDeck;
    private Spread? selectedSpread; // null = free draw
    private int freeDrawCount;        // New Reading session working value
    private int defaultFreeDrawCount; // Settings default (persisted independently)
    private DateTime defaultsSavedAt = DateTime.MinValue; // drives the transient "Saved ✓"

    private string newReadingTitle = string.Empty;
    private bool hideUser;
    private string newReadingPassword = string.Empty;

    private volatile bool generating;
    private string? generateError;

    private IReadOnlyList<Reading> myReadings = Array.Empty<Reading>();
    private bool myReadingsRequested;
    private volatile bool myReadingsLoading;
    private string? myReadingsError;
    private string myReadingsFilter = string.Empty;

    private bool switchToReadingTab;
    private bool switchToNewReadingTab;
    private bool switchToSettingsTab;

    public MainWindow(
        TarotApiClient api,
        ReadingPanel readingPanel,
        LinkService linkService,
        DrawKeyService drawKeys,
        Configuration config,
        IDalamudPluginInterface pluginInterface)
        : base("TarotGen###TarotGenMain")
    {
        this.api = api;
        this.readingPanel = readingPanel;
        this.linkService = linkService;
        this.drawKeys = drawKeys;
        this.config = config;
        this.pluginInterface = pluginInterface;
        this.freeDrawCount = Math.Max(1, config.FreeDrawCount);
        this.defaultFreeDrawCount = Math.Max(1, config.FreeDrawCount);
        this.Size = new Vector2(680, 780);
        this.SizeCondition = ImGuiCond.FirstUseEver;
        this.SizeConstraints = new WindowSizeConstraints
        {
            MinimumSize = new Vector2(460, 420),
            MaximumSize = new Vector2(1400, 2000),
        };
    }

    /// <summary>Open a reading by code and switch to the Reading tab.</summary>
    public void ShowReading(string idOrUrl)
    {
        this.readingPanel.OpenReading(idOrUrl);
        this.switchToReadingTab = true;
        this.IsOpen = true;
    }

    /// <summary>Open the window on the Settings tab (the config/gear button).</summary>
    public void ShowSettings()
    {
        this.switchToSettingsTab = true;
        this.IsOpen = true;
    }

    public override void Draw()
    {
        if (!this.catalogRequested)
            this.StartCatalogLoad();

        // First-run onboarding — shown once, ever. The popup id must match the
        // PopupModal below exactly (### makes only the trailing id part hash).
        if (!this.config.OnboardingComplete && !this.onboardingShown)
        {
            this.onboardingShown = true;
            ImGui.OpenPopup("Welcome to TarotGen###onboarding");
        }

        // Set up before draw keys existed: tell the player once why drawing stopped
        // and how to get a key. A linked install can still draw, so it never asks.
        if (this.config.OnboardingComplete && !this.config.DrawKeyNoticeSeen && !this.drawKeyNoticeShown)
        {
            this.drawKeyNoticeShown = true;
            if (this.api.CanDraw)
                this.MarkDrawKeyNoticeSeen();
            else
                ImGui.OpenPopup("Drawing now needs a draw key###drawKeyNotice");
        }

        using (var tabs = ImRaii.TabBar("##tarotgen-tabs"))
        {
            if (tabs)
            {
                var readingFlags = this.switchToReadingTab ? ImGuiTabItemFlags.SetSelected : ImGuiTabItemFlags.None;
                this.switchToReadingTab = false;
                using (var t = ImRaii.TabItem("Reading", readingFlags))
                {
                    if (t)
                        this.readingPanel.Draw();
                }

                var newReadingFlags = this.switchToNewReadingTab ? ImGuiTabItemFlags.SetSelected : ImGuiTabItemFlags.None;
                this.switchToNewReadingTab = false;
                using (var t = ImRaii.TabItem("New Reading", newReadingFlags))
                {
                    if (t)
                        this.DrawNewReadingTab();
                }

                // My Readings requires an actual linked TarotGen account (not a guest).
                if (this.api.IsLinked)
                {
                    using var t = ImRaii.TabItem("My Readings");
                    if (t)
                        this.DrawMyReadingsTab();
                }

                var settingsFlags = this.switchToSettingsTab ? ImGuiTabItemFlags.SetSelected : ImGuiTabItemFlags.None;
                this.switchToSettingsTab = false;
                using (var t = ImRaii.TabItem("Settings", settingsFlags))
                {
                    if (t)
                        this.DrawSettingsTab();
                }
            }
        }

        this.DrawOnboarding();
        this.DrawDrawKeyNotice();
    }

    private void DrawOnboarding()
    {
        var vp = ImGui.GetMainViewport();
        var scale = ImGuiHelpers.GlobalScale;
        ImGui.SetNextWindowPos(vp.WorkPos + (vp.WorkSize / 2f), ImGuiCond.Appearing, new Vector2(0.5f, 0.5f));
        ImGui.SetNextWindowSize(new Vector2(440 * scale, 0), ImGuiCond.Appearing);

        using var popup = ImRaii.PopupModal("Welcome to TarotGen###onboarding", ImGuiWindowFlags.AlwaysAutoResize);
        if (!popup)
            return;

        ImGui.PushTextWrapPos(420 * scale);
        ImGui.TextWrapped("Draw and view TarotGen.io tarot readings without leaving the game.");
        ImGui.Spacing();
        ImGui.TextColored(Ui.SectionColor, "How it works");
        ImGui.BulletText("New Reading: pick a deck + spread (or a free draw) and draw.");
        ImGui.BulletText("Without an account, your first draw asks for a draw key: a quick check in your browser.");
        ImGui.BulletText("Reading: view the cards, click one for full-res, copy the share code.");
        ImGui.BulletText("Share a reading's code in chat — anyone can open it with /tarot <code>.");
        ImGui.Spacing();
        ImGui.TextColored(Ui.SectionColor, "Optional: connect your account");
        ImGui.TextWrapped(
            "Link your TarotGen.io account to lock readings, see \"My Readings\", and unlock more.");
        ImGui.PopTextWrapPos();

        ImGui.Spacing();
        ImGui.Separator();

        if (Ui.PrimaryButton("Connect to TarotGen.io"))
        {
            this.linkService.StartLink();
            this.CompleteOnboarding();
        }

        ImGui.SameLine();
        if (Ui.Button("Maybe later"))
            this.CompleteOnboarding();
    }

    private void CompleteOnboarding()
    {
        this.config.OnboardingComplete = true;
        // Onboarding already explains draw keys; the upgrade notice is not for this install.
        this.config.DrawKeyNoticeSeen = true;
        this.config.Save(this.pluginInterface);
        ImGui.CloseCurrentPopup();
    }

    private void DrawDrawKeyNotice()
    {
        var vp = ImGui.GetMainViewport();
        var scale = ImGuiHelpers.GlobalScale;
        ImGui.SetNextWindowPos(vp.WorkPos + (vp.WorkSize / 2f), ImGuiCond.Appearing, new Vector2(0.5f, 0.5f));
        ImGui.SetNextWindowSize(new Vector2(460 * scale, 0), ImGuiCond.Appearing);

        using var popup = ImRaii.PopupModal("Drawing now needs a draw key###drawKeyNotice", ImGuiWindowFlags.AlwaysAutoResize);
        if (!popup)
            return;

        ImGui.PushTextWrapPos(440 * scale);
        ImGui.TextWrapped(
            "To keep bots out, TarotGen.io now asks for a quick check that you're a person before drawing "
            + "without an account. The game can't show that check, so this plugin needs a draw key. You get "
            + "one once:");
        ImGui.Spacing();
        ImGui.TextColored(Ui.SectionColor, "How to get one");
        ImGui.BulletText("Click \"Get a draw key\" below (it's on the New Reading tab too).");
        ImGui.BulletText("Your browser opens TarotGen.io: pass the check, then click \"Get Draw Key\".");
        ImGui.BulletText("Come back to the game. The plugin keeps the key and draws as before.");
        ImGui.Spacing();
        ImGui.TextWrapped(
            "Or link your TarotGen.io account instead: a linked plugin draws without a key, and your "
            + "readings go to My Readings.");
        ImGui.PopTextWrapPos();

        ImGui.Spacing();
        ImGui.Separator();

        if (Ui.PrimaryButton("Get a draw key"))
        {
            this.drawKeys.StartGetKey();
            // Its progress and any error show on the New Reading tab.
            this.switchToNewReadingTab = true;
            this.CloseDrawKeyNotice();
        }

        ImGui.SameLine();
        if (Ui.Button("Link my account"))
        {
            this.linkService.StartLink();
            this.switchToSettingsTab = true;
            this.CloseDrawKeyNotice();
        }

        ImGui.SameLine();
        if (Ui.Button("Later"))
            this.CloseDrawKeyNotice();
    }

    private void CloseDrawKeyNotice()
    {
        this.MarkDrawKeyNoticeSeen();
        ImGui.CloseCurrentPopup();
    }

    private void MarkDrawKeyNoticeSeen()
    {
        this.config.DrawKeyNoticeSeen = true;
        this.config.Save(this.pluginInterface);
    }

    // ── New Reading tab ───────────────────────────────────────────────────────

    private void DrawNewReadingTab()
    {
        if (this.catalogLoading)
        {
            ImGui.TextUnformatted("Loading decks and spreads…");
            return;
        }

        if (this.catalogError != null)
        {
            ImGui.TextColored(ErrorColor, this.catalogError);
            if (Ui.Button("Retry"))
                this.StartCatalogLoad();
            return;
        }

        Ui.Section(FontAwesomeIcon.LayerGroup, "Deck");
        using (Ui.Body())
        {
            this.DrawDeckCombo();
            if (this.selectedDeck is { AdditionalCards: > 0 })
            {
                var additional = this.config.UseAdditionalCards;
                if (ImGui.Checkbox($"Include this deck's {this.selectedDeck.AdditionalCards} extra card(s)", ref additional))
                    this.config.UseAdditionalCards = additional;
            }
        }

        Ui.Section(FontAwesomeIcon.Table, "Spread");
        using (Ui.Body())
        {
            this.DrawSpreadCombo();
            if (this.selectedSpread != null)
                DrawSpreadDetailsColumns(this.selectedSpread);
            else
                this.DrawFreeDrawCount();
        }

        Ui.Section(FontAwesomeIcon.SlidersH, "Options");
        using (Ui.Body())
        {
            var reversals = this.config.UseReversals;
            if (ImGui.Checkbox("Reversed cards", ref reversals))
                this.config.UseReversals = reversals;

            if (this.api.IsLinked)
                this.DrawOwnerOptions();
            else
                Ui.Help("Link your account (Settings tab) to set a title, hide your name, or add a password.");
        }

        ImGui.Spacing();
        this.DrawGenerateButton();
    }

    private void DrawOwnerOptions()
    {
        var scale = ImGuiHelpers.GlobalScale;
        ImGui.SetNextItemWidth(-1);
        ImGui.InputTextWithHint("##rtitle", "Reading title (optional)", ref this.newReadingTitle, 100);
        ImGui.Checkbox("Hide my name", ref this.hideUser);
        ImGui.SetNextItemWidth(240 * scale);
        ImGui.InputTextWithHint("##rpw", "Password (optional)", ref this.newReadingPassword, 128, ImGuiInputTextFlags.Password);
    }

    private void DrawGenerateButton()
    {
        if (!this.api.CanDraw)
        {
            this.DrawGetKeyPrompt();
            return;
        }

        var scale = ImGuiHelpers.GlobalScale;
        using (ImRaii.Disabled(this.generating || this.selectedDeck == null))
        {
            if (Ui.PrimaryButton("Generate Reading", new Vector2(-1, 36 * scale)))
                this.StartGenerate();
        }

        if (this.generating)
            ImGui.TextUnformatted("Drawing…");
        if (this.generateError != null)
            ImGui.TextColored(ErrorColor, this.generateError);
    }

    /// <summary>
    /// An unlinked install without a draw key. The site asks a signed-out draw to
    /// pass a browser challenge, which the game can't show, so the player passes
    /// it once on the site and the install keeps the key it gets.
    /// </summary>
    private void DrawGetKeyPrompt()
    {
        ImGui.TextWrapped(
            "To draw without an account, this install needs a draw key. Getting one opens TarotGen.io in "
            + "your browser for a quick check that you're a person; after that, drawing doesn't ask again.");
        Ui.Help("Or link your account on the Settings tab.");
        ImGui.Spacing();

        using (ImRaii.Disabled(this.drawKeys.IsBusy))
        {
            if (Ui.PrimaryButton("Get a draw key", new Vector2(-1, 36 * ImGuiHelpers.GlobalScale)))
            {
                this.generateError = null;
                this.drawKeys.StartGetKey();
            }
        }

        if (this.drawKeys.IsBusy && Ui.Button("Cancel"))
            this.drawKeys.Cancel();

        if (!string.IsNullOrEmpty(this.drawKeys.Status))
            ImGui.TextWrapped(this.drawKeys.Status);
        // Why the key went, when the site turned it down on a draw.
        if (this.generateError != null)
            ImGui.TextColored(ErrorColor, this.generateError);
    }

    private void DrawDeckCombo()
    {
        var label = this.selectedDeck?.Name ?? "Select a deck…";
        ImGui.SetNextItemWidth(-1);
        using var combo = ImRaii.Combo("##deck", label);
        if (!combo)
            return;

        var first = true;
        foreach (var group in this.deckGroups)
        {
            if (!first)
                ImGui.Separator();
            first = false;

            ImGui.TextDisabled(group.Header);
            foreach (var d in group.Decks)
            {
                var extra = d.AdditionalCards > 0 ? $"  (+{d.AdditionalCards})" : string.Empty;
                var artist = string.IsNullOrWhiteSpace(d.Artist) ? string.Empty : $"   ·  {d.Artist}";
                if (ImGui.Selectable($"{d.Name}{artist}{extra}##deck{d.DeckId}", d == this.selectedDeck))
                    this.SelectDeck(d);
            }
        }
    }

    // New Reading picks are session-only; the persisted defaults live on the Settings tab.
    private void SelectDeck(Deck deck)
    {
        this.selectedDeck = deck;
        this.config.UseAdditionalCards = false;
        this.config.Save(this.pluginInterface);
    }

    private void SelectSpread(Spread? spread)
    {
        this.selectedSpread = spread;
    }

    private void DrawSpreadCombo()
    {
        var label = this.selectedSpread?.Name ?? "Free draw";
        ImGui.SetNextItemWidth(-1);
        using var combo = ImRaii.Combo("##spread", label);
        if (!combo)
            return;

        if (ImGui.Selectable("Free draw", this.selectedSpread == null))
            this.SelectSpread(null);

        this.DrawSpreadItems("spread", s => s == this.selectedSpread, this.SelectSpread);
    }

    /// <summary>
    /// A spread picker's list after its "Free draw": each category's heading over
    /// its spreads, or one list with no headings when the API sent no categories.
    /// </summary>
    private void DrawSpreadItems(string id, Func<Spread, bool> isSelected, Action<Spread> pick)
    {
        foreach (var group in this.spreadGroups)
        {
            if (group.Header != null)
            {
                ImGui.Separator();
                ImGui.TextDisabled(group.Header);
            }

            foreach (var s in group.Spreads)
            {
                var cards = s.CardCount == 1 ? "1 card" : $"{s.CardCount} cards";
                if (ImGui.Selectable($"{s.Name}   ({cards})##{id}{s.SpreadId}", isSelected(s)))
                    pick(s);
            }
        }
    }

    private void DrawFreeDrawCount()
    {
        var max = this.MaxCardsForDeck();
        if (this.freeDrawCount > max)
            this.freeDrawCount = max;
        ImGui.SetNextItemWidth(200 * ImGuiHelpers.GlobalScale);
        ImGui.SliderInt("Number of cards", ref this.freeDrawCount, 1, max);
    }

    private static void DrawSpreadDetailsColumns(Spread spread)
    {
        var positions = spread.Positions.OrderBy(p => p.Order).ToList();

        using var table = ImRaii.Table("##spreaddetail", 2, ImGuiTableFlags.BordersInnerV);
        if (!table)
            return;

        ImGui.TableSetupColumn("Preview", ImGuiTableColumnFlags.WidthStretch, 0.55f);
        ImGui.TableSetupColumn("Details", ImGuiTableColumnFlags.WidthStretch, 0.45f);
        ImGui.TableNextRow();

        ImGui.TableNextColumn();
        DrawSpreadPreview(positions);

        ImGui.TableNextColumn();
        ImGui.TextDisabled(spread.CardCount == 1 ? "1 card" : $"{spread.CardCount} cards");
        if (!string.IsNullOrWhiteSpace(spread.Description))
            ImGui.TextWrapped(spread.Description);
        ImGui.Spacing();
        foreach (var p in positions)
        {
            var title = string.IsNullOrWhiteSpace(p.Title) ? $"Position {p.Order}" : p.Title;
            ImGui.TextWrapped($"{p.Order}. {title}");
            if (!string.IsNullOrWhiteSpace(p.Description))
            {
                using (Ui.Body())
                    Ui.HelpWrapped(p.Description);
            }
        }
    }

    private static void DrawSpreadPreview(List<SpreadPosition> positions)
    {
        if (positions.Count == 0)
            return;

        var fit = SpreadLayout.ComputeFit(positions);
        var sizePct = SpreadLayout.CardSizePct(fit);
        var scale = ImGuiHelpers.GlobalScale;
        var canvas = Math.Clamp(ImGui.GetContentRegionAvail().X, 120f, 440f * scale);

        var origin = ImGui.GetCursorScreenPos();
        ImGui.Dummy(new Vector2(canvas, canvas));
        var dl = ImGui.GetWindowDrawList();
        dl.AddRect(origin, origin + new Vector2(canvas, canvas), 0x22FFFFFFu, 4f);
        var cardWpx = sizePct.X / 100f * canvas;
        var cardHpx = sizePct.Y / 100f * canvas;

        foreach (var p in positions)
        {
            var proj = SpreadLayout.Project(fit, p.X, p.Y);
            var center = origin + new Vector2(proj.X / 100f * canvas, proj.Y / 100f * canvas);
            SpreadDraw.RotatedRect(dl, center, cardWpx, cardHpx, p.Rotation, 0x66FFFFFFu);
            SpreadDraw.OrderNumber(dl, center, p.Order);
        }
    }

    // ── My Readings tab ───────────────────────────────────────────────────────

    private void DrawMyReadingsTab()
    {
        if (!this.api.IsLinked)
        {
            ImGui.TextWrapped("Link your TarotGen account (Settings tab) to see the readings saved to your account.");
            return;
        }

        if (!this.myReadingsRequested)
            this.StartLoadMyReadings();

        if (Ui.Button("Refresh"))
            this.StartLoadMyReadings();
        ImGui.SameLine();
        ImGui.SetNextItemWidth(-1);
        ImGui.InputTextWithHint("##filter", "Filter by title, deck, or ID…", ref this.myReadingsFilter, 100);

        if (this.myReadingsLoading)
        {
            ImGui.TextUnformatted("Loading…");
            return;
        }

        if (this.myReadingsError != null)
        {
            ImGui.TextColored(ErrorColor, this.myReadingsError);
            return;
        }

        var q = this.myReadingsFilter.Trim();
        var rows = this.myReadings.Where(r => this.Matches(r, q)).ToList();
        if (rows.Count == 0)
        {
            ImGui.TextDisabled(q.Length > 0 ? "No readings match your filter." : "No saved readings yet.");
            return;
        }

        using var table = ImRaii.Table("##myreadings", 4,
            ImGuiTableFlags.RowBg | ImGuiTableFlags.Borders | ImGuiTableFlags.Resizable | ImGuiTableFlags.SizingStretchProp);
        if (!table)
            return;

        ImGui.TableSetupColumn("Title");
        ImGui.TableSetupColumn("Reading ID", ImGuiTableColumnFlags.WidthFixed, 110 * ImGuiHelpers.GlobalScale);
        ImGui.TableSetupColumn("Deck");
        ImGui.TableSetupColumn("Date", ImGuiTableColumnFlags.WidthFixed, 150 * ImGuiHelpers.GlobalScale);
        ImGui.TableHeadersRow();

        foreach (var r in rows)
        {
            ImGui.TableNextRow();
            ImGui.TableNextColumn();
            if (ImGui.Selectable($"{ReadingFormat.Title(r)}##row{r.ReadingId}", false, ImGuiSelectableFlags.SpanAllColumns))
                this.ShowReading(r.ReadingId);
            ImGui.TableNextColumn();
            ImGui.TextUnformatted(r.ReadingId);
            ImGui.TableNextColumn();
            ImGui.TextUnformatted(this.DeckName(r));
            ImGui.TableNextColumn();
            ImGui.TextUnformatted(r.ReadingTime ?? string.Empty);
        }
    }

    private bool Matches(Reading r, string query)
    {
        if (query.Length == 0)
            return true;
        return ReadingFormat.Title(r).Contains(query, StringComparison.OrdinalIgnoreCase)
            || r.ReadingId.Contains(query, StringComparison.OrdinalIgnoreCase)
            || this.DeckName(r).Contains(query, StringComparison.OrdinalIgnoreCase);
    }

    private string DeckName(Reading r)
    {
        var id = r.ReadingInfo?.DeckId ?? 0;
        return this.api.DeckById(id)?.Name ?? "—";
    }

    // ── Account tab ───────────────────────────────────────────────────────────

    private void DrawSettingsTab()
    {
        // ── Defaults ────────────────────────────────────────────────────────
        var defaultsOpen = Ui.CollapsingSection(FontAwesomeIcon.Bookmark, "Defaults");
        ImGui.SameLine();
        Ui.HelpMarker(
            "The deck, spread, and card count a New Reading starts on. Your picks on the "
            + "New Reading tab are temporary and never overwrite these.");
        if (defaultsOpen)
        {
            using (Ui.Body())
                this.DrawDefaultsSection();
        }

        // ── Connection ──────────────────────────────────────────────────────
        if (Ui.CollapsingSection(FontAwesomeIcon.Plug, "Connection"))
        {
            using (Ui.Body())
                this.DrawConnectionSection();
        }

        // ── Links (never collapsed) ────────────────────────────
        DrawLinksSection();
    }

    private void DrawDefaultsSection()
    {
        if (this.catalogLoading)
        {
            Ui.Help("Loading decks and spreads…");
            return;
        }

        if (this.catalogError != null)
        {
            ImGui.TextColored(ErrorColor, this.catalogError);
            return;
        }

        Ui.Label("Default deck");
        this.DrawDefaultDeckCombo();
        Ui.Label("Default spread");
        this.DrawDefaultSpreadCombo();
        Ui.Label("Default cards on free draw");
        ImGui.SetNextItemWidth(200 * ImGuiHelpers.GlobalScale);
        ImGui.SliderInt("##defcards", ref this.defaultFreeDrawCount, 1, 78);
        if (ImGui.IsItemDeactivatedAfterEdit())
            this.SaveDefaults();

        // Defaults already save on change; this button + confirmation is purely for
        // peace of mind, so it can never be "unsaved".
        ImGui.Spacing();
        if (Ui.Button("Save defaults"))
            this.SaveDefaults();
        if ((DateTime.UtcNow - this.defaultsSavedAt).TotalSeconds < 2.5)
        {
            ImGui.SameLine();
            Ui.Icon(FontAwesomeIcon.Check, Ui.SuccessColor);
            ImGui.SameLine(0, ImGui.GetStyle().ItemInnerSpacing.X);
            ImGui.TextColored(Ui.SuccessColor, "Saved");
        }
    }

    private void SaveDefaults()
    {
        this.config.FreeDrawCount = this.defaultFreeDrawCount;
        this.config.Save(this.pluginInterface);
        this.defaultsSavedAt = DateTime.UtcNow;
    }

    private void DrawDefaultDeckCombo()
    {
        var current = this.deckGroups.SelectMany(g => g.Decks).FirstOrDefault(d => d.DeckId == this.config.DefaultDeckId);
        ImGui.SetNextItemWidth(-1);
        using var combo = ImRaii.Combo("##defaultdeck", current?.Name ?? "Select a deck…");
        if (!combo)
            return;

        var first = true;
        foreach (var group in this.deckGroups)
        {
            if (!first)
                ImGui.Separator();
            first = false;

            ImGui.TextDisabled(group.Header);
            foreach (var d in group.Decks)
            {
                var extra = d.AdditionalCards > 0 ? $"  (+{d.AdditionalCards})" : string.Empty;
                var artist = string.IsNullOrWhiteSpace(d.Artist) ? string.Empty : $"   ·  {d.Artist}";
                if (ImGui.Selectable($"{d.Name}{artist}{extra}##ddeck{d.DeckId}", d.DeckId == this.config.DefaultDeckId))
                {
                    this.config.DefaultDeckId = d.DeckId;
                    this.config.Save(this.pluginInterface);
                    this.defaultsSavedAt = DateTime.UtcNow;
                }
            }
        }
    }

    private void DrawDefaultSpreadCombo()
    {
        var current = this.config.DefaultSpreadId is { } sid
            ? this.spreads.FirstOrDefault(s => s.SpreadId == sid)
            : null;
        ImGui.SetNextItemWidth(-1);
        using var combo = ImRaii.Combo("##defaultspread", current?.Name ?? "Free draw");
        if (!combo)
            return;

        if (ImGui.Selectable("Free draw", this.config.DefaultSpreadId == null))
            this.SaveDefaultSpread(null);

        this.DrawSpreadItems("dspread", s => s.SpreadId == this.config.DefaultSpreadId, s => this.SaveDefaultSpread(s.SpreadId));
    }

    private void SaveDefaultSpread(int? spreadId)
    {
        this.config.DefaultSpreadId = spreadId;
        this.config.Save(this.pluginInterface);
        this.defaultsSavedAt = DateTime.UtcNow;
    }

    private void DrawConnectionSection()
    {
        if (this.api.IsLinked)
        {
            ImGui.TextColored(Ui.SuccessColor, $"Linked as {this.api.LinkedName}");
            Ui.Help("Account features (locking, favorites, My Readings) are available.");

            ImGui.Spacing();
            using (ImRaii.Disabled(this.linkService.IsBusy))
            {
                if (Ui.Button("Disconnect"))
                    this.linkService.Unlink();
            }
        }
        else
        {
            ImGui.TextWrapped(
                "Link your TarotGen.io account for account features (locking, favorites, and "
                + "My Readings). Drawing and viewing readings works without an account.");
            Ui.Help(this.api.HasDrawKey
                ? "This install has a draw key, so it draws without an account."
                : "Without an account, drawing needs a draw key: the New Reading tab gets one.");
            ImGui.Spacing();
            using (ImRaii.Disabled(this.linkService.IsBusy))
            {
                if (Ui.PrimaryButton("Connect to TarotGen.io", new Vector2(-1, 34 * ImGuiHelpers.GlobalScale)))
                    this.linkService.StartLink();
            }
        }

        if (this.linkService.IsBusy)
        {
            if (Ui.Button("Cancel"))
                this.linkService.Cancel();
        }

        // Only show the transient link status (progress / errors). Its terminal
        // success message ("Linked as X") duplicates the label above, so suppress
        // the status once we're settled into a linked state.
        if (!string.IsNullOrEmpty(this.linkService.Status) && (this.linkService.IsBusy || !this.api.IsLinked))
        {
            ImGui.Spacing();
            ImGui.TextWrapped(this.linkService.Status);
        }
    }

    private static void DrawLinksSection()
    {
        Ui.Section(FontAwesomeIcon.Link, "Links");

        // Two aligned rows: label + a small coloured link button.
        using (var t = ImRaii.Table("##links", 2, ImGuiTableFlags.SizingFixedFit))
        {
            if (t)
            {
                ImGui.TableSetupColumn("l", ImGuiTableColumnFlags.WidthFixed, 92 * ImGuiHelpers.GlobalScale);

                ImGui.TableNextColumn();
                ImGui.AlignTextToFramePadding();
                Ui.Help("Tarot API");
                ImGui.TableNextColumn();
                if (Ui.ColorButton(
                        "TarotGen.io",
                        new Vector4(0.20f, 0.47f, 0.58f, 1f),
                        new Vector4(0.30f, 0.62f, 0.74f, 1f),
                        new Vector4(0.16f, 0.40f, 0.50f, 1f)))
                {
                    Util.OpenLink(Configuration.SiteUrl);
                }

                ImGui.TableNextColumn();
                ImGui.AlignTextToFramePadding();
                Ui.Help("Designed by");
                ImGui.TableNextColumn();
                if (Ui.ColorButton(
                        "MathDad",
                        new Vector4(0.72f, 0.18f, 0.20f, 1f),
                        new Vector4(0.86f, 0.26f, 0.28f, 1f),
                        new Vector4(0.60f, 0.14f, 0.16f, 1f)))
                {
                    Util.OpenLink("https://mathdad.me");
                }
            }
        }

        ImGui.Spacing();
        Ui.Help($"TarotGen plugin  v{PluginVersion}");
    }

    // ── Loading + actions ─────────────────────────────────────────────────────

    private int MaxCardsForDeck()
    {
        if (this.selectedDeck == null)
            return 78;
        var total = this.selectedDeck.EffectiveTotalCards;
        if (this.config.UseAdditionalCards)
            total += this.selectedDeck.AdditionalCards;
        return Math.Max(1, total);
    }

    private void StartCatalogLoad()
    {
        this.catalogRequested = true;
        this.catalogLoading = true;
        this.catalogError = null;
        _ = Task.Run(async () =>
        {
            try
            {
                var decksTask = this.api.GetDecksAsync(this.api.Token);
                var spreadsTask = this.api.GetSpreadsAsync(this.api.Token);
                await Task.WhenAll(decksTask, spreadsTask).ConfigureAwait(false);

                var spreads = spreadsTask.Result.OrderBy(s => s.CardCount).ThenBy(s => s.Name).ToList();
                this.spreads = spreads;
                this.spreadGroups = BuildSpreadGroups(spreads);
                this.deckGroups = BuildDeckGroups(decksTask.Result);

                this.selectedDeck = null;
                var allDecks = this.deckGroups.SelectMany(g => g.Decks).ToList();
                if (this.config.DefaultDeckId is { } deckId)
                    this.selectedDeck = allDecks.FirstOrDefault(d => d.DeckId == deckId);
                this.selectedDeck ??= allDecks.FirstOrDefault();

                this.selectedSpread = this.config.DefaultSpreadId is { } spreadId
                    ? this.spreads.FirstOrDefault(s => s.SpreadId == spreadId)
                    : null;
            }
            catch (Exception ex)
            {
                this.catalogError = $"Couldn't load decks/spreads: {ex.Message}";
            }
            finally
            {
                this.catalogLoading = false;
            }
        });
    }

    /// <summary>
    /// Public spreads under their library category, in the admins' order, as New
    /// Draw groups them on the site. An API before 5.2.0 sends no categories: one
    /// group with no heading, a flat picker.
    /// </summary>
    private static List<SpreadGroup> BuildSpreadGroups(List<Spread> spreads)
    {
        if (spreads.TrueForAll(s => string.IsNullOrWhiteSpace(s.CategoryName)))
            return [new SpreadGroup(null, spreads)];

        return spreads
            .GroupBy(s => s.CategoryId)
            .OrderBy(g => g.First().CategorySortOrder)
            .Select(g => new SpreadGroup(g.First().CategoryName, g.ToList()))
            .ToList();
    }

    private static List<DeckGroup> BuildDeckGroups(IReadOnlyList<Deck> decks)
    {
        return decks
            .GroupBy(d => string.IsNullOrWhiteSpace(d.SystemShortName) ? "Other" : d.SystemShortName)
            .OrderBy(g => g.Key, StringComparer.OrdinalIgnoreCase)
            .Select(g =>
            {
                var ordered = g.OrderBy(d => d.Name, StringComparer.OrdinalIgnoreCase).ToList();
                var total = ordered[0].EffectiveTotalCards;
                var header = $"{g.Key.ToUpperInvariant()}  ·  {total} cards";
                return new DeckGroup(header, ordered);
            })
            .ToList();
    }

    private void StartGenerate()
    {
        if (this.selectedDeck == null)
            return;

        var linked = this.api.IsLinked;
        var request = new GenerateRequest
        {
            DeckId = this.selectedDeck.DeckId,
            NumberOfCards = this.selectedSpread?.CardCount ?? this.freeDrawCount,
            UseReversals = this.config.UseReversals,
            UseAdditionalCards = this.config.UseAdditionalCards,
            SpreadId = this.selectedSpread?.SpreadId,
            ReadingName = linked && !string.IsNullOrWhiteSpace(this.newReadingTitle) ? this.newReadingTitle.Trim() : null,
            HideUser = linked && this.hideUser,
            Password = linked && !string.IsNullOrEmpty(this.newReadingPassword) ? this.newReadingPassword : null,
        };

        this.generating = true;
        this.generateError = null;
        _ = Task.Run(async () =>
        {
            try
            {
                var reading = await this.api.GenerateAsync(request, this.api.Token).ConfigureAwait(false);
                if (reading != null)
                {
                    // The plugin can't draw more cards, so lock an owned reading right
                    // away — plugin-generated readings are always final.
                    if (reading.IsOwner && !reading.IsFinal)
                    {
                        var locked = await this.api.FinalizeReadingAsync(reading.ReadingId, this.api.Token).ConfigureAwait(false);
                        if (locked != null)
                            reading = locked;
                    }

                    this.readingPanel.SetReading(reading);
                    this.switchToReadingTab = true;
                    this.myReadingsRequested = false; // refresh the list next view
                }
            }
            catch (Exception ex)
            {
                this.generateError = ex.Message;
            }
            finally
            {
                this.generating = false;
            }
        });
    }

    private void StartLoadMyReadings()
    {
        this.myReadingsRequested = true;
        this.myReadingsLoading = true;
        this.myReadingsError = null;
        _ = Task.Run(async () =>
        {
            try
            {
                var result = await this.api.GetMyReadingsAsync(this.api.Token).ConfigureAwait(false);
                this.myReadings = result ?? new List<Reading>();
            }
            catch (Exception ex)
            {
                this.myReadingsError = ex.Message;
            }
            finally
            {
                this.myReadingsLoading = false;
            }
        });
    }

    public void Dispose()
    {
    }

    private sealed record DeckGroup(string Header, List<Deck> Decks);

    /// <summary>A category's spreads; no <see cref="Header"/> when the API sent no categories.</summary>
    private sealed record SpreadGroup(string? Header, List<Spread> Spreads);
}
