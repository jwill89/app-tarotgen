using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

public class CatalogTests
{
    private static readonly List<Deck> Decks = FakeServer.Fixture<List<Deck>>("decks.json");
    private static readonly List<Spread> Spreads = FakeServer.Fixture<List<Spread>>("spreads.json");

    [Fact]
    public void DecksAreListedUnderTheirSystemByName()
    {
        var catalog = new Catalog(Decks, Spreads);

        Assert.Equal(["LENORMAND  ·  36 cards", "RWS  ·  78 cards"], catalog.DeckGroups.Select(g => g.Header));
        Assert.Equal(["Gilded Hour Tarot", "Moonlit Path Tarot"], catalog.DeckGroups[1].Decks.Select(d => d.Name));
        Assert.Equal([7, 4, 1], catalog.Decks.Select(d => d.DeckId));
    }

    [Fact]
    public void ADeckWithNoSystemIsListedUnderOther()
    {
        var catalog = new Catalog([new Deck { DeckId = 2, Name = "Loose Leaves" }], []);

        Assert.Equal("OTHER  ·  78 cards", Assert.Single(catalog.DeckGroups).Header);
    }

    [Fact]
    public void SpreadsAreListedUnderTheirCategoryInTheAdminsOrder()
    {
        var catalog = new Catalog(Decks, Spreads);

        Assert.Equal(["General", "Love & relationships", "Decisions"], catalog.SpreadGroups.Select(g => g.Header));
        Assert.Equal(["Past, Present, Future", "Celtic Cross"], catalog.SpreadGroups[0].Spreads.Select(s => s.Name));
    }

    [Fact]
    public void SpreadsGoFewestCardsFirstThenByName()
    {
        var catalog = new Catalog(Decks, Spreads);

        Assert.Equal([9, 3, 6, 1], catalog.Spreads.Select(s => s.SpreadId));
    }

    [Fact]
    public void SpreadsFromBeforeCategoriesAreOneListWithNoHeading()
    {
        var uncategorised = Spreads.Select(s => new Spread { SpreadId = s.SpreadId, Name = s.Name, CardCount = s.CardCount }).ToList();

        var group = Assert.Single(new Catalog(Decks, uncategorised).SpreadGroups);

        Assert.Null(group.Header);
        Assert.Equal(4, group.Spreads.Count);
    }

    [Fact]
    public void AnEmptyCatalogListsNothing()
    {
        Assert.Empty(Catalog.Empty.DeckGroups);
        Assert.Empty(Catalog.Empty.SpreadGroups);
        Assert.Null(Catalog.Empty.StartingDeck(1));
    }

    [Theory]
    [InlineData(1, 1)]
    [InlineData(99, 7)]
    [InlineData(null, 7)]
    public void ANewReadingStartsOnTheSavedDeckElseTheFirstListed(int? saved, int starting) =>
        Assert.Equal(starting, new Catalog(Decks, Spreads).StartingDeck(saved)?.DeckId);

    [Theory]
    [InlineData(6, "Two Paths")]
    [InlineData(42, null)]
    [InlineData(null, null)]
    public void ASavedSpreadIsFoundById(int? saved, string? name) =>
        Assert.Equal(name, new Catalog(Decks, Spreads).SpreadById(saved)?.Name);

    [Theory]
    [InlineData(78, 2, false, 78)]
    [InlineData(78, 2, true, 80)]
    [InlineData(0, 0, false, 78)]
    [InlineData(36, 0, true, 36)]
    public void AFreeDrawTakesUpToTheWholeDeck(int systemCards, int extra, bool withExtra, int most) =>
        Assert.Equal(most, new Deck { SystemTotalCards = systemCards, AdditionalCards = extra }.MaxDrawCount(withExtra));
}

public class ReadingFormatTests
{
    [Fact]
    public void ANamedReadingIsTitledByItsName() =>
        Assert.Equal("Before the move", ReadingFormat.Title(FakeServer.Fixture<Reading>("reading-celtic-cross.json")));

    [Fact]
    public void AnUnnamedReadingIsTitledByItsSpread() =>
        Assert.Equal("Past, Present, Future", ReadingFormat.Title(FakeServer.Fixture<Reading>("reading-spread.json")));

    [Fact]
    public void AFreeDrawIsTitledByItsCardCount()
    {
        Assert.Equal("Free Draw (5 cards)", ReadingFormat.Title(FakeServer.Fixture<Reading>("reading-free.json")));

        var single = new Reading { ReadingName = "  ", ReadingInfo = new ReadingInfo { Draw = [new DrawCard()] } };
        Assert.Equal("Free Draw (1 card)", ReadingFormat.Title(single));
        Assert.Equal("Free Draw (0 cards)", ReadingFormat.Title(new Reading()));
    }

    [Theory]
    [InlineData("", true)]
    [InlineData("   ", true)]
    [InlineData("before THE", true)]
    [InlineData("B7E01D", true)]
    [InlineData("gilded", true)]
    [InlineData(" celtic ", false)]
    [InlineData("lenormand", false)]
    public void MyReadingsFilterByTitleIdOrDeck(string query, bool kept)
    {
        var reading = FakeServer.Fixture<List<Reading>>("account-readings.json")[0];

        Assert.Equal(kept, ReadingFormat.Matches(reading, "Gilded Hour Tarot", query));
    }
}
