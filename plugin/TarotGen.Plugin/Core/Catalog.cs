namespace TarotGen.Plugin.Core;

/// <summary>A deck system's decks, under a header naming the system and its card count.</summary>
public sealed record DeckGroup(string Header, IReadOnlyList<Deck> Decks);

/// <summary>A library category's spreads; no <see cref="Header"/> when the API sent no categories.</summary>
public sealed record SpreadGroup(string? Header, IReadOnlyList<Spread> Spreads);

/// <summary>
/// The decks and public spreads as the pickers list them: decks under their deck
/// system, by name; spreads under their library category in the admins' order, as
/// New Draw groups them on the site.
/// </summary>
public sealed class Catalog
{
    public static readonly Catalog Empty = new([], []);

    public Catalog(IEnumerable<Deck> decks, IEnumerable<Spread> spreads)
    {
        this.Spreads = spreads.OrderBy(s => s.CardCount).ThenBy(s => s.Name, StringComparer.OrdinalIgnoreCase).ToList();
        this.SpreadGroups = GroupSpreads(this.Spreads);
        this.DeckGroups = GroupDecks(decks);
    }

    /// <summary>Every spread, fewest cards first, then by name.</summary>
    public IReadOnlyList<Spread> Spreads { get; }

    public IReadOnlyList<SpreadGroup> SpreadGroups { get; }

    public IReadOnlyList<DeckGroup> DeckGroups { get; }

    /// <summary>Every deck, in the order the picker lists them.</summary>
    public IEnumerable<Deck> Decks => this.DeckGroups.SelectMany(g => g.Decks);

    public Deck? DeckById(int? id) => this.Decks.FirstOrDefault(d => d.DeckId == id);

    public Spread? SpreadById(int? id) => this.Spreads.FirstOrDefault(s => s.SpreadId == id);

    /// <summary>The deck a New Reading starts on: the saved default, else the first listed.</summary>
    public Deck? StartingDeck(int? defaultDeckId) => this.DeckById(defaultDeckId) ?? this.Decks.FirstOrDefault();

    /// <summary>One group with no heading when the API sent no categories (before 5.2.0).</summary>
    private static List<SpreadGroup> GroupSpreads(IReadOnlyList<Spread> spreads)
    {
        if (spreads.All(s => string.IsNullOrWhiteSpace(s.CategoryName)))
            return spreads.Count == 0 ? [] : [new SpreadGroup(null, spreads)];

        return spreads
            .GroupBy(s => s.CategoryId)
            .OrderBy(g => g.First().CategorySortOrder)
            .Select(g => new SpreadGroup(g.First().CategoryName, g.ToList()))
            .ToList();
    }

    private static List<DeckGroup> GroupDecks(IEnumerable<Deck> decks)
    {
        return decks
            .GroupBy(d => string.IsNullOrWhiteSpace(d.SystemShortName) ? "Other" : d.SystemShortName)
            .OrderBy(g => g.Key, StringComparer.OrdinalIgnoreCase)
            .Select(g =>
            {
                var ordered = g.OrderBy(d => d.Name, StringComparer.OrdinalIgnoreCase).ToList();
                var header = $"{g.Key.ToUpperInvariant()}  ·  {ordered[0].EffectiveTotalCards} cards";
                return new DeckGroup(header, ordered);
            })
            .ToList();
    }
}
