using System.Collections.Concurrent;
using System.Numerics;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface;
using Dalamud.Interface.Utility;
using Dalamud.Interface.Utility.Raii;
using Dalamud.Utility;
using TarotGen.Plugin.Models;
using TarotGen.Plugin.Services;

namespace TarotGen.Plugin.Windows;

/// <summary>
/// Renders a single reading inside the main window's "Reading" tab. For a spread
/// it lays the cards out on a square canvas at their spread positions (matching
/// the web layout) and lists each position's meaning; for a free draw it shows a
/// wrapped grid. Cards are always face-up; clicking one opens a full-res lightbox.
/// </summary>
public sealed class ReadingPanel
{
    private static readonly Vector4 ErrorColor = new(1f, 0.45f, 0.45f, 1f);
    private static readonly Vector4 AccentColor = new(0.85f, 0.75f, 0.5f, 1f);

    private readonly TarotApiClient api;
    private readonly CardTextureCache textures;

    private Reading? reading;
    private string codeBuffer = "";
    private string passwordBuffer = "";
    private volatile bool loading;
    private string? error;

    private LightboxCard? lightbox;
    private bool lightboxShowReversed;
    private bool openLightboxRequest;

    // Card problem reporting (from the lightbox). Keyed by (deckId, cardId);
    // ConcurrentDictionary so the background report task and Draw don't race.
    private readonly ConcurrentDictionary<(int, int), byte> reportedCards = new();
    private volatile bool reportingCard;
    private string? reportCardStatus;

    // The lightbox's report form: which reason is picked (an index into
    // CardReportReason.All, -1 = none yet) and what the user has written.
    private bool reportFormOpen;
    private int reportReason = -1;
    private string reportDetails = "";

    public ReadingPanel(TarotApiClient api, CardTextureCache textures)
    {
        this.api = api;
        this.textures = textures;
    }

    public bool HasReading => this.reading != null;

    public void SetReading(Reading value)
    {
        this.reading = value;
        this.error = null;
        this.passwordBuffer = "";
    }

    public void OpenReading(string idOrUrl)
    {
        this.codeBuffer = idOrUrl;
        this.StartLoad(idOrUrl);
    }

    public void Draw()
    {
        this.DrawContent();

        // Process a card-click from this frame, then render the lightbox popup.
        if (this.openLightboxRequest)
        {
            ImGui.OpenPopup("##cardLightbox");
            this.openLightboxRequest = false;
        }

        this.DrawLightbox();
    }

    private void DrawContent()
    {
        ImGui.SetNextItemWidth(220 * ImGuiHelpers.GlobalScale);
        ImGui.InputTextWithHint("##code", "reading code or share URL", ref this.codeBuffer, 256);
        ImGui.SameLine();
        using (ImRaii.Disabled(this.loading || string.IsNullOrWhiteSpace(this.codeBuffer)))
        {
            if (Ui.Button("Open"))
                this.StartLoad(this.codeBuffer);
        }

        ImGui.Separator();

        if (this.error != null)
            ImGui.TextColored(ErrorColor, this.error);

        if (this.loading)
        {
            ImGui.TextUnformatted("Loading…");
            return;
        }

        if (this.reading == null)
        {
            ImGui.TextDisabled("Generate a reading, or open one by its share code above.");
            return;
        }

        if (this.reading.Locked)
        {
            this.DrawLocked();
            return;
        }

        this.DrawReading(this.reading);
    }

    private void DrawLocked()
    {
        ImGui.TextUnformatted($"\"{this.reading!.ReadingName ?? "This reading"}\" is password protected.");
        ImGui.SetNextItemWidth(200 * ImGuiHelpers.GlobalScale);
        ImGui.InputText("##pw", ref this.passwordBuffer, 128, ImGuiInputTextFlags.Password);
        ImGui.SameLine();
        using (ImRaii.Disabled(this.loading || string.IsNullOrEmpty(this.passwordBuffer)))
        {
            if (Ui.Button("Unlock"))
                this.StartUnlock(this.reading!.ReadingId, this.passwordBuffer);
        }
    }

    private void DrawReading(Reading r)
    {
        var info = r.ReadingInfo;

        ImGui.TextUnformatted(ReadingFormat.Title(r));
        var meta = $"by {r.Reader ?? "Guest"}";
        if (info?.Spread != null)
            meta += $"  ·  {info.Spread.Name}";
        if (!string.IsNullOrEmpty(r.ReadingTime))
            meta += $"  ·  {r.ReadingTime}";
        ImGui.TextDisabled(meta);

        var url = $"{Configuration.SiteUrl}/reading/{r.ReadingId}";

        // The share code is what people paste into a /tell or party chat.
        ImGui.Spacing();
        ImGui.AlignTextToFramePadding();
        Ui.Label("Share code:");
        ImGui.SameLine();
        ImGui.AlignTextToFramePadding();
        ImGui.TextColored(AccentColor, r.ReadingId);
        ImGui.SameLine();
        if (Ui.IconButton("copycode", FontAwesomeIcon.Copy, "Copy code"))
            ImGui.SetClipboardText(r.ReadingId);
        ImGui.SameLine();
        if (Ui.IconButton("copylink", FontAwesomeIcon.Link, "Copy link"))
            ImGui.SetClipboardText(url);
        ImGui.SameLine();
        ImGui.AlignTextToFramePadding();
        Ui.HelpMarker("Paste the code into a /tell or party chat — anyone can open it with /tarot <code> or on the website.");

        if (Ui.Button("Open in browser"))
            Util.OpenLink(url);

        // No lock control here: plugin-generated readings are always auto-locked on
        // creation, so there is nothing to lock and no state worth surfacing.

        if (info?.Spread != null && !string.IsNullOrWhiteSpace(info.Spread.Description))
        {
            ImGui.Spacing();
            ImGui.TextWrapped(info.Spread.Description);
        }

        ImGui.Separator();
        ImGui.TextDisabled("Click a card to view it full size.");

        if (info == null || info.Draw.Count == 0)
        {
            ImGui.TextDisabled("This reading has no cards.");
            return;
        }

        if (info.Spread != null && info.Spread.Positions.Count > 0)
            this.DrawSpread(info);
        else
            this.DrawGrid(info);
    }

    // ── Spread reading: positioned canvas + per-position details ──────────────

    private void DrawSpread(ReadingInfo info)
    {
        var positions = info.Spread!.Positions.OrderBy(p => p.Order).ToList();
        var cards = info.Draw;

        // Two columns: the positioned canvas on the left, the per-position card
        // meanings on the right — mirroring the website and the New Reading preview.
        using var table = ImRaii.Table("##readingspread", 2,
            ImGuiTableFlags.BordersInnerV | ImGuiTableFlags.Resizable);
        if (!table)
        {
            this.DrawSpreadCanvas(info, positions, cards);
            Ui.Section(FontAwesomeIcon.ListUl, "Card Details");
            DrawDetails(positions, cards);
            return;
        }

        ImGui.TableSetupColumn("Spread", ImGuiTableColumnFlags.WidthStretch, 0.58f);
        ImGui.TableSetupColumn("Details", ImGuiTableColumnFlags.WidthStretch, 0.42f);
        ImGui.TableNextRow();

        ImGui.TableNextColumn();
        this.DrawSpreadCanvas(info, positions, cards);

        ImGui.TableNextColumn();
        Ui.Section(FontAwesomeIcon.ListUl, "Card Details");
        DrawDetails(positions, cards);
    }

    private void DrawSpreadCanvas(ReadingInfo info, List<SpreadPosition> positions, List<DrawCard> cards)
    {
        var fit = SpreadLayout.ComputeFit(positions);
        var sizePct = SpreadLayout.CardSizePct(fit);

        var scale = ImGuiHelpers.GlobalScale;
        var avail = ImGui.GetContentRegionAvail().X;
        var canvas = Math.Clamp(avail, 180f, 460f * scale);

        var origin = ImGui.GetCursorScreenPos();
        ImGui.InvisibleButton("##spreadcanvas", new Vector2(canvas, canvas));
        var canvasClicked = ImGui.IsItemClicked();

        var dl = ImGui.GetWindowDrawList();
        dl.AddRect(origin, origin + new Vector2(canvas, canvas), 0x22FFFFFFu, 6f);

        var cardWpx = sizePct.X / 100f * canvas;
        var cardHpx = sizePct.Y / 100f * canvas;

        Vector2 CenterOf(SpreadPosition p)
        {
            var proj = SpreadLayout.Project(fit, p.X, p.Y);
            return origin + new Vector2(proj.X / 100f * canvas, proj.Y / 100f * canvas);
        }

        for (var i = 0; i < positions.Count; i++)
        {
            var p = positions[i];
            var center = CenterOf(p);
            var card = i < cards.Count ? cards[i] : null;
            var tex = card != null ? this.textures.GetCard(info.DeckId, card.CardId) : null;

            if (tex != null)
                SpreadDraw.RotatedImage(dl, tex.Handle, center, cardWpx, cardHpx, p.Rotation, card!.Reversed);
            else
                SpreadDraw.RotatedRect(dl, center, cardWpx, cardHpx, p.Rotation, 0x55FFFFFFu); // loading placeholder
        }

        for (var i = 0; i < positions.Count; i++)
        {
            var center = CenterOf(positions[i]);
            var badge = center + new Vector2(-cardWpx / 2f + 9f * scale, -cardHpx / 2f + 9f * scale);
            SpreadDraw.OrderBadge(dl, badge, positions[i].Order, scale);
        }

        // Click → lightbox (topmost card wins).
        if (canvasClicked)
        {
            var mouse = ImGui.GetMousePos();
            for (var i = positions.Count - 1; i >= 0; i--)
            {
                if (SpreadDraw.HitTest(mouse, CenterOf(positions[i]), cardWpx, cardHpx, positions[i].Rotation)
                    && i < cards.Count)
                {
                    this.OpenLightbox(info.DeckId, cards[i], positions[i].Title);
                    break;
                }
            }
        }
    }

    private static void DrawDetails(List<SpreadPosition> positions, List<DrawCard> cards)
    {
        for (var i = 0; i < positions.Count; i++)
        {
            var p = positions[i];
            var card = i < cards.Count ? cards[i] : null;

            ImGui.TextColored(AccentColor, $"{p.Order}.");
            ImGui.SameLine();
            if (!string.IsNullOrWhiteSpace(p.Title))
            {
                ImGui.TextUnformatted(p.Title);
                ImGui.SameLine();
                ImGui.TextDisabled("—");
                ImGui.SameLine();
            }

            if (card != null)
                ImGui.TextUnformatted(card.CardName + (card.Reversed ? "  (reversed)" : ""));
            else
                ImGui.TextDisabled("—");

            // What the position asks, when the spread says: under its line, never in place of the title.
            if (!string.IsNullOrWhiteSpace(p.Description))
            {
                using (Ui.Body())
                    Ui.HelpWrapped(p.Description);
            }
        }
    }

    // ── Free draw: a wrapped grid of cards ────────────────────────────────────

    private void DrawGrid(ReadingInfo info)
    {
        var deck = this.api.DeckById(info.DeckId);
        var aw = deck?.CardAspectW ?? 5.0;
        var ah = deck?.CardAspectH ?? 8.6;
        var scale = ImGuiHelpers.GlobalScale;
        var cardW = 100f * scale;
        var cardH = (float)(cardW * (ah / aw));
        var spacing = ImGui.GetStyle().ItemSpacing.X;
        var avail = ImGui.GetContentRegionAvail().X;
        var perRow = Math.Max(1, (int)(avail / (cardW + spacing)));

        for (var i = 0; i < info.Draw.Count; i++)
        {
            if (i % perRow != 0)
                ImGui.SameLine();
            this.DrawGridCard(info.DeckId, i + 1, info.Draw[i], cardW, cardH);
        }
    }

    private void DrawGridCard(int deckId, int order, DrawCard card, float cardW, float cardH)
    {
        ImGui.BeginGroup();

        var size = new Vector2(cardW, cardH);
        var topLeft = ImGui.GetCursorScreenPos();
        ImGui.InvisibleButton($"##gcard{deckId}_{order}_{card.CardId}", size);
        var clicked = ImGui.IsItemClicked();

        var dl = ImGui.GetWindowDrawList();
        var tex = this.textures.GetCard(deckId, card.CardId);
        if (tex != null)
        {
            var uvMin = card.Reversed ? new Vector2(1, 1) : Vector2.Zero;
            var uvMax = card.Reversed ? Vector2.Zero : Vector2.One;
            dl.AddImage(tex.Handle, topLeft, topLeft + size, uvMin, uvMax, 0xFFFFFFFFu);
        }
        else
        {
            dl.AddRectFilled(topLeft, topLeft + size, 0x40303030u, 4f);
            dl.AddRect(topLeft, topLeft + size, 0x33FFFFFFu, 4f);
        }

        var scale = ImGuiHelpers.GlobalScale;
        SpreadDraw.OrderBadge(dl, topLeft + new Vector2(9f * scale, 9f * scale), order, scale);

        ImGui.PushTextWrapPos(ImGui.GetCursorPosX() + cardW);
        ImGui.TextUnformatted(card.CardName + (card.Reversed ? "  (rev)" : ""));
        ImGui.PopTextWrapPos();

        ImGui.EndGroup();

        if (clicked)
            this.OpenLightbox(deckId, card);
    }

    // ── Lightbox ──────────────────────────────────────────────────────────────

    private void OpenLightbox(int deckId, DrawCard card, string? positionTitle = null)
    {
        this.lightbox = new LightboxCard(deckId, card.CardId, card.CardName, card.Reversed, positionTitle);
        this.lightboxShowReversed = card.Reversed;
        this.reportCardStatus = null;
        this.reportFormOpen = false;
        this.reportReason = -1;
        this.reportDetails = "";
        this.openLightboxRequest = true;
    }

    private void DrawLightbox()
    {
        var vp = ImGui.GetMainViewport();
        // Re-centred every frame (the popup can't be moved anyway) so it stays on
        // screen as the report form grows and shrinks it.
        ImGui.SetNextWindowPos(vp.WorkPos + (vp.WorkSize / 2f), ImGuiCond.Always, new Vector2(0.5f, 0.5f));

        using var popup = ImRaii.Popup("##cardLightbox", ImGuiWindowFlags.NoMove | ImGuiWindowFlags.AlwaysAutoResize);
        if (!popup)
        {
            this.lightbox = null;
            return;
        }

        if (this.lightbox is not { } lb)
        {
            ImGui.CloseCurrentPopup();
            return;
        }

        // Once the report goes through the card counts as reported, which hides the form.
        var reported = this.reportedCards.ContainsKey((lb.DeckId, lb.CardId));
        var showReportForm = this.reportFormOpen && !reported;

        var tex = this.textures.GetCard(lb.DeckId, lb.CardId);
        if (tex != null)
        {
            var nat = tex.Size;
            // Give the report form room below the card while it's open.
            var maxH = vp.WorkSize.Y * (showReportForm ? 0.5f : 0.82f);
            var maxW = vp.WorkSize.X * 0.9f;
            var scale = Math.Min(maxH / Math.Max(1f, nat.Y), maxW / Math.Max(1f, nat.X));
            var display = new Vector2(nat.X * scale, nat.Y * scale);

            var uvMin = this.lightboxShowReversed ? new Vector2(1, 1) : Vector2.Zero;
            var uvMax = this.lightboxShowReversed ? Vector2.Zero : Vector2.One;
            ImGui.Image(tex.Handle, display, uvMin, uvMax);
        }
        else
        {
            ImGui.TextUnformatted("Loading…");
        }

        // Card name with its position meaning, plus orientation.
        var caption = lb.Name;
        if (!string.IsNullOrWhiteSpace(lb.PositionTitle))
            caption = $"{lb.PositionTitle}  ·  {caption}";
        if (lb.Reversed)
            caption += this.lightboxShowReversed ? "  (Reversed)" : "  (shown upright)";
        ImGui.TextWrapped(caption);

        if (lb.Reversed)
        {
            if (Ui.Button(this.lightboxShowReversed ? "Flip upright" : "Show reversed"))
                this.lightboxShowReversed = !this.lightboxShowReversed;
            ImGui.SameLine();
        }

        if (Ui.Button("Close"))
            ImGui.CloseCurrentPopup();

        ImGui.SameLine();
        using (ImRaii.Disabled(this.reportingCard || reported))
        {
            if (Ui.Button(reported ? "Reported" : "Report a problem"))
                this.reportFormOpen = !this.reportFormOpen;
        }

        if (showReportForm)
            this.DrawReportForm(lb);

        if (!string.IsNullOrEmpty(this.reportCardStatus))
            ImGui.TextDisabled(this.reportCardStatus);
    }

    /// <summary>
    /// What's wrong with the card, and where: the reason tells the admins what kind
    /// of fix it needs (a re-scan, a re-crop, a different image), the details where
    /// to look. "Something else" needs details, since it says nothing on its own.
    /// </summary>
    private void DrawReportForm(LightboxCard lb)
    {
        var scale = ImGuiHelpers.GlobalScale;
        var width = Math.Max(ImGui.GetContentRegionAvail().X, 340f * scale);

        ImGui.Spacing();
        ImGui.Separator();
        Ui.Label("What's wrong?");
        for (var i = 0; i < CardReportReason.All.Count; i++)
        {
            var option = CardReportReason.All[i];
            if (ImGui.RadioButton($"{option.Label}##reason{i}", this.reportReason == i))
                this.reportReason = i;
            ImGui.SameLine();
            Ui.Help(option.Hint);
        }

        var chosen = this.reportReason >= 0 ? CardReportReason.All[this.reportReason] : null;

        ImGui.Spacing();
        Ui.Label(chosen?.RequiresDetails == true ? "Details (required)" : "Details (optional)");
        Ui.Help(chosen?.Prompt ?? "Anything that helps us find the problem");
        // ImGui caps the buffer in UTF-8 bytes, so non-ASCII text stops a little short
        // of the server's character limit — never past it.
        ImGui.InputTextMultiline(
            "##reportDetails",
            ref this.reportDetails,
            CardReportReason.MaxDetailsLength,
            new Vector2(width, ImGui.GetTextLineHeight() * 4.5f));
        ImGui.TextDisabled($"{this.reportDetails.Length} / {CardReportReason.MaxDetailsLength}");

        var canSend = chosen != null && (!chosen.RequiresDetails || !string.IsNullOrWhiteSpace(this.reportDetails));
        using (ImRaii.Disabled(this.reportingCard || !canSend))
        {
            if (Ui.PrimaryButton("Send report") && chosen != null)
                this.StartReportCard(lb.DeckId, lb.CardId, lb.Name, chosen.Key, this.reportDetails.Trim());
        }

        ImGui.SameLine();
        if (Ui.Button("Cancel"))
            this.reportFormOpen = false;
    }

    // ── Async actions ─────────────────────────────────────────────────────────

    private void StartLoad(string idOrUrl)
    {
        var id = TarotApiClient.ExtractReadingId(idOrUrl);
        if (id == null)
        {
            this.error = "That doesn't look like a reading code or URL.";
            return;
        }

        this.loading = true;
        this.error = null;
        _ = Task.Run(async () =>
        {
            try
            {
                this.reading = await this.api.GetReadingAsync(id, this.api.Token).ConfigureAwait(false);
                this.passwordBuffer = "";
            }
            catch (Exception ex)
            {
                this.error = ex.Message;
            }
            finally
            {
                this.loading = false;
            }
        });
    }

    private void StartUnlock(string readingId, string password)
    {
        this.loading = true;
        this.error = null;
        _ = Task.Run(async () =>
        {
            try
            {
                this.reading = await this.api.UnlockReadingAsync(readingId, password, this.api.Token).ConfigureAwait(false);
                this.passwordBuffer = "";
            }
            catch (Exception ex)
            {
                this.error = ex.Message;
            }
            finally
            {
                this.loading = false;
            }
        });
    }

    private void StartReportCard(int deckId, int cardId, string cardName, string reason, string details)
    {
        this.reportingCard = true;
        this.reportCardStatus = "Sending report…";
        _ = Task.Run(async () =>
        {
            try
            {
                var status = await this.api.ReportCardAsync(deckId, cardId, cardName, reason, details, this.api.Token)
                    .ConfigureAwait(false);
                // Marking the card reported is what hides the form (see DrawLightbox).
                this.reportedCards[(deckId, cardId)] = 0;
                this.reportCardStatus = status == "already_reported"
                    ? "You've already reported this card recently. Thanks!"
                    : "Thanks! Your report has been sent.";
            }
            catch (TarotApiException ex)
            {
                this.reportCardStatus = ex.Message;
            }
            catch (Exception)
            {
                this.reportCardStatus = "Couldn't send the report. Please try again.";
            }
            finally
            {
                this.reportingCard = false;
            }
        });
    }

    private readonly record struct LightboxCard(int DeckId, int CardId, string Name, bool Reversed, string? PositionTitle);
}
