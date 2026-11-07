namespace TarotGen.Plugin.Models;

// DTOs mirror the TarotGen backend response shapes. JSON is (de)serialized with
// JsonNamingPolicy.SnakeCaseLower (see TarotApiClient), so C# PascalCase maps to
// the API's snake_case (DeckId <-> deck_id, ReadingInfo <-> reading_info, …).
// Sources: backend Tarot\Structure\{Deck,Spread}.php and Service\ReadingService.php,
// frontend src/types/index.ts.

/// <summary>A tarot deck (GET /api/decks).</summary>
public sealed class Deck
{
    public int DeckId { get; set; }
    public int DeckSystemId { get; set; }
    public string Name { get; set; } = "";
    public string Artist { get; set; } = "";
    public string PurchaseUrl { get; set; } = "";
    public int AdditionalCards { get; set; }
    public double CardAspectW { get; set; } = 5.0;
    public double CardAspectH { get; set; } = 8.6;
    public bool Approved { get; set; } = true;
    public bool Usable { get; set; } = true;
    public int? SubmittedBy { get; set; }
    public string SystemShortName { get; set; } = "";
    public int SystemTotalCards { get; set; }

    /// <summary>Standard card count, falling back to 78 when the system is missing.</summary>
    public int EffectiveTotalCards => this.SystemTotalCards > 0 ? this.SystemTotalCards : 78;
}

/// <summary>A deck system (GET /api/deck-systems).</summary>
public sealed class DeckSystem
{
    public int DeckSystemId { get; set; }
    public string Name { get; set; } = "";
    public string ShortName { get; set; } = "";
    public int TotalCards { get; set; }
    public bool Approved { get; set; } = true;
    public int? SubmittedBy { get; set; }
}

/// <summary>One position within a spread layout.</summary>
public sealed class SpreadPosition
{
    public int Order { get; set; }
    public string Title { get; set; } = "";
    public double X { get; set; }
    public double Y { get; set; }
    public int Rotation { get; set; }
    /// <summary>What the position asks; empty when it has none (or the API predates 5.2.0).</summary>
    public string Description { get; set; } = "";
}

/// <summary>A predefined public spread (GET /api/spreads).</summary>
public sealed class Spread
{
    public int SpreadId { get; set; }
    public string Name { get; set; } = "";
    public string Description { get; set; } = "";
    public int CardCount { get; set; } = 1;
    // The library category it's listed under, and that category's place in the
    // admins' list. An API before 5.2.0 sends none: an empty name, order 0.
    public int CategoryId { get; set; }
    public string CategoryName { get; set; } = "";
    public int CategorySortOrder { get; set; }
    public List<SpreadPosition> Positions { get; set; } = new();
}

/// <summary>The spread snapshot embedded in a reading's reading_info.</summary>
public sealed class SpreadSnapshot
{
    public int SpreadId { get; set; }
    public string Name { get; set; } = "";
    public string Description { get; set; } = "";
    public List<SpreadPosition> Positions { get; set; } = new();
}

/// <summary>One drawn card inside reading_info.draw.</summary>
public sealed class DrawCard
{
    public int CardId { get; set; }
    public bool Reversed { get; set; }
    public string CardName { get; set; } = "";
}

/// <summary>The decoded reading_info object.</summary>
public sealed class ReadingInfo
{
    public int DeckId { get; set; }
    public List<DrawCard> Draw { get; set; } = new();
    public SpreadSnapshot? Spread { get; set; }
    /// <summary>"generated" or "custom".</summary>
    public string? Origin { get; set; }
}

/// <summary>
/// A reading (POST /api/readings/generate, GET /api/readings/{id}). When a
/// password reading is viewed by a non-owner the API returns only
/// { locked = true, reading_name } — <see cref="Locked"/> guards that.
/// </summary>
public sealed class Reading
{
    public string ReadingId { get; set; } = "";
    public ReadingInfo? ReadingInfo { get; set; }
    public string ReadingTime { get; set; } = "";
    public string? ReadingName { get; set; }
    public string? ReadingNotes { get; set; }
    public string? Reader { get; set; }
    public bool IsOwner { get; set; }
    public bool IsFinal { get; set; }
    public bool CanDrawMore { get; set; }
    public bool Locked { get; set; }
}

/// <summary>
/// Request body for POST /api/readings/generate. A spread (when set) dictates the
/// card count server-side; for a free draw, <see cref="NumberOfCards"/> is used.
/// </summary>
public sealed class GenerateRequest
{
    public int DeckId { get; set; }
    public int NumberOfCards { get; set; } = 1;
    public bool UseReversals { get; set; }
    public bool UseAdditionalCards { get; set; }
    /// <summary>Null / omitted for a free draw.</summary>
    public int? SpreadId { get; set; }

    // Owner options — honored by the server only for a linked account.
    public string? ReadingName { get; set; }
    public bool HideUser { get; set; }
    public string? Password { get; set; }
}

/// <summary>Ack body from POST /api/card-reports ("reported" | "already_reported").</summary>
public sealed class CardReportAck
{
    public string? Status { get; set; }
}

/// <summary>
/// A reason a card can be reported for. <see cref="Key"/> is the API value (the
/// backend's CardReportReason enum); the label, hint, and prompt are this plugin's
/// copy, matching the website's report form.
/// </summary>
public sealed record CardReportReason(string Key, string Label, string Hint, string Prompt)
{
    /// <summary>The server keeps at most this many characters of details.</summary>
    public const int MaxDetailsLength = 500;

    public static readonly IReadOnlyList<CardReportReason> All =
    [
        new("artefact", "Scan artefact", "Dust, scratches, glare, or smudges on the image.",
            "Where is it? e.g. a dust speck near the top-left corner"),
        new("cropping", "Cropped or crooked", "Edges cut off, a tilted scan, or uneven borders.",
            "Which edge? e.g. the bottom border is cut off"),
        new("quality", "Poor image quality", "Blurry, low resolution, too dark, or washed out.",
            "What looks off? e.g. the whole card is blurry"),
        new("wrong_card", "Wrong card", "The image doesn't match the card's name.",
            "Which card does the image show instead?"),
        new("other", "Something else", "Tell us what you noticed below.",
            "Describe what's wrong with this card"),
    ];

    /// <summary>"Something else" says nothing on its own, so the server requires details for it.</summary>
    public bool RequiresDetails => this.Key == "other";
}
