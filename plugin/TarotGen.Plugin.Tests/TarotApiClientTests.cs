using System.Net;
using System.Text.Json;
using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

public class TarotApiClientTests
{
    private const string AccountToken = "tg_pat_0a1b2c3d4e5f60718293a4b5c6d7e8f9";
    private const string DrawKey = "tg_dk_f9e8d7c6b5a4938271605f4e3d2c1b0a";

    private static CancellationToken Ct => TestContext.Current.CancellationToken;

    [Fact]
    public async Task DecksAreFetchedOnceAndFoundById()
    {
        var server = new FakeServer().Answer("GET /decks", "decks.json");
        using var api = server.Client(new MemoryCredentials());

        var decks = await api.GetDecksAsync(Ct);
        await api.GetDecksAsync(Ct);

        Assert.Equal(3, decks.Count);
        Assert.Single(server.Requests);
        Assert.Equal("Gilded Hour Tarot", api.DeckById(4)?.Name);
        Assert.Null(api.DeckById(99));
    }

    [Fact]
    public async Task SpreadsAreFetchedOnce()
    {
        var server = new FakeServer().Answer("GET /spreads", "spreads.json");
        using var api = server.Client(new MemoryCredentials());

        await api.GetSpreadsAsync(Ct);
        var spreads = await api.GetSpreadsAsync(Ct);

        Assert.Equal(4, spreads.Count);
        Assert.Single(server.Requests);
    }

    [Fact]
    public async Task SpreadsTheSiteCouldNotSendAreAskedForAgain()
    {
        var server = new FakeServer().Answer("GET /spreads", "problem-unavailable.json", HttpStatusCode.ServiceUnavailable);
        using var api = server.Client(new MemoryCredentials());

        var refused = await Assert.ThrowsAsync<TarotApiException>(() => api.GetSpreadsAsync(Ct));
        server.Answer("GET /spreads", "spreads.json");
        var spreads = await api.GetSpreadsAsync(Ct);

        Assert.Equal("TarotGen can't be reached right now. Try again in a minute.", refused.Message);
        Assert.Equal(4, spreads.Count);
        Assert.Equal(2, server.Requests.Count);
    }

    [Theory]
    [InlineData(AccountToken, DrawKey, "Bearer " + AccountToken)]
    [InlineData(null, DrawKey, null)]
    [InlineData(null, null, null)]
    public async Task ReadsCarryTheAccountTokenAndNeverTheDrawKey(string? token, string? key, string? sent)
    {
        var server = new FakeServer().Answer("GET /readings/4f9c2a7b1e", "reading-spread.json");
        using var api = server.Client(new MemoryCredentials(token, key));

        await api.GetReadingAsync("4f9c2a7b1e", Ct);

        Assert.Equal(sent, Assert.Single(server.Requests).Authorization);
    }

    [Theory]
    [InlineData(AccountToken, DrawKey, "Bearer " + AccountToken)]
    [InlineData(AccountToken, null, "Bearer " + AccountToken)]
    [InlineData(null, DrawKey, "Bearer " + DrawKey)]
    [InlineData(null, null, null)]
    public async Task ADrawIsMadeAsTheLinkedAccountElseWithTheDrawKey(string? token, string? key, string? sent)
    {
        var server = new FakeServer().Answer("POST /readings/generate", "reading-spread.json", HttpStatusCode.Created);
        using var api = server.Client(new MemoryCredentials(token, key));

        var reading = await api.GenerateAsync(new GenerateRequest { DeckId = 1, SpreadId = 3 }, Ct);

        Assert.Equal("4f9c2a7b1e", reading?.ReadingId);
        Assert.Equal(sent, Assert.Single(server.Requests).Authorization);
    }

    [Fact]
    public async Task ADrawKeyTheSiteRefusesIsForgotten()
    {
        var credentials = new MemoryCredentials(drawKey: DrawKey);
        var server = new FakeServer().Answer("POST /readings/generate", "problem-draw-key-revoked.json", HttpStatusCode.Unauthorized);
        using var api = server.Client(credentials);

        var refused = await Assert.ThrowsAsync<TarotApiException>(() => api.GenerateAsync(new GenerateRequest { DeckId = 1 }, Ct));

        Assert.Equal(401, refused.StatusCode);
        Assert.Equal("This draw key is no longer valid. Get a new one from the plugin.", refused.Message);
        Assert.Null(credentials.DrawKey);
        Assert.False(api.CanDraw);
    }

    [Fact]
    public async Task AnAccountLinkTheSiteRefusesIsForgottenAndTheDrawKeyKept()
    {
        var credentials = new MemoryCredentials(AccountToken, DrawKey, "Mira Ashgrove");
        var server = new FakeServer().AnswerWith(
            "POST /readings/generate",
            HttpStatusCode.Unauthorized,
            """{"type":"about:blank","title":"Unauthorized","status":401,"detail":"This account link is no longer valid. Link your account again in the plugin."}""");
        using var api = server.Client(credentials);

        await Assert.ThrowsAsync<TarotApiException>(() => api.GenerateAsync(new GenerateRequest { DeckId = 1 }, Ct));

        Assert.False(api.IsLinked);
        Assert.Equal(string.Empty, api.LinkedName);
        Assert.Equal(DrawKey, credentials.DrawKey);
        Assert.True(api.CanDraw);
    }

    [Fact]
    public async Task AKeyThatArrivesWhileTheOldOneIsRefusedIsKept()
    {
        const string newKey = "tg_dk_00112233445566778899aabbccddeeff";
        var credentials = new MemoryCredentials(drawKey: DrawKey);
        var server = new FakeServer().Answer("POST /readings/generate", "problem-draw-key-revoked.json", HttpStatusCode.Unauthorized);
        server.OnRequest = () => credentials.SaveDrawKey(newKey);
        using var api = server.Client(credentials);

        await Assert.ThrowsAsync<TarotApiException>(() => api.GenerateAsync(new GenerateRequest { DeckId = 1 }, Ct));

        Assert.Equal(newKey, credentials.DrawKey);
    }

    [Fact]
    public async Task AWrongReadingPasswordKeepsEveryCredential()
    {
        var credentials = new MemoryCredentials(AccountToken, DrawKey, "Mira Ashgrove");
        var server = new FakeServer().AnswerWith(
            "POST /readings/b7e01d93c4/unlock",
            HttpStatusCode.Unauthorized,
            """{"type":"about:blank","title":"Unauthorized","status":401,"detail":"That password isn't right."}""");
        using var api = server.Client(credentials);

        var refused = await Assert.ThrowsAsync<TarotApiException>(() => api.UnlockReadingAsync("b7e01d93c4", "hunter2", Ct));

        Assert.Equal("That password isn't right.", refused.Message);
        Assert.Equal(AccountToken, credentials.Token);
        Assert.Equal(DrawKey, credentials.DrawKey);
        Assert.Equal("hunter2", Assert.Single(server.Requests).Json.GetProperty("password").GetString());
    }

    [Fact]
    public async Task ARefusedReportSaysWhyAndIsLogged()
    {
        var warnings = new List<string>();
        var server = new FakeServer().Answer("POST /card-reports", "problem-report-invalid.json", HttpStatusCode.UnprocessableContent);
        using var api = server.Client(new MemoryCredentials(), warnings);

        var refused = await Assert.ThrowsAsync<TarotApiException>(() => api.ReportCardAsync(1, 18, "The Star", "other", string.Empty, Ct));

        Assert.Equal(422, refused.StatusCode);
        Assert.Equal("Tell us what's wrong with this card.", refused.Message);
        Assert.Equal("TarotGen API 422: Tell us what's wrong with this card.", Assert.Single(warnings));
    }

    [Fact]
    public async Task AnOlderServersErrorStillReads()
    {
        var server = new FakeServer().AnswerWith("GET /readings/zz", HttpStatusCode.BadRequest, """{"error":"InvalidReadingID"}""", "application/json");
        using var api = server.Client(new MemoryCredentials());

        var refused = await Assert.ThrowsAsync<TarotApiException>(() => api.GetReadingAsync("zz", Ct));

        Assert.Equal(400, refused.StatusCode);
        Assert.Equal("InvalidReadingID", refused.Message);
    }

    [Fact]
    public async Task AProxysErrorPageReadsAsItsStatus()
    {
        var server = new FakeServer().AnswerWith("GET /decks", HttpStatusCode.BadGateway, "<html><body>Bad gateway</body></html>", "text/html");
        using var api = server.Client(new MemoryCredentials());

        var refused = await Assert.ThrowsAsync<TarotApiException>(() => api.GetDecksAsync(Ct));

        Assert.Equal("HTTP 502", refused.Message);
    }

    [Fact]
    public async Task NoContentReadsAsNothing()
    {
        var server = new FakeServer().AnswerWith("POST /readings/b7e01d93c4/finalize", HttpStatusCode.NoContent, string.Empty);
        using var api = server.Client(new MemoryCredentials(AccountToken, linkedName: "Mira Ashgrove"));

        Assert.Null(await api.FinalizeReadingAsync("b7e01d93c4", Ct));
        Assert.Equal("Bearer " + AccountToken, Assert.Single(server.Requests).Authorization);
    }

    [Fact]
    public async Task AFreeDrawIsSentInSnakeCaseWithoutTheOptionsLeftEmpty()
    {
        var server = new FakeServer().Answer("POST /readings/generate", "reading-free.json", HttpStatusCode.Created);
        using var api = server.Client(new MemoryCredentials(drawKey: DrawKey));

        await api.GenerateAsync(new GenerateRequest { DeckId = 4, NumberOfCards = 5, UseReversals = true }, Ct);

        var body = Assert.Single(server.Requests).Json;
        Assert.Equal(
            ["deck_id", "number_of_cards", "use_reversals", "use_additional_cards", "hide_user"],
            body.EnumerateObject().Select(p => p.Name));
        Assert.Equal(5, body.GetProperty("number_of_cards").GetInt32());
        Assert.True(body.GetProperty("use_reversals").GetBoolean());
    }

    [Fact]
    public async Task ALinkedSpreadDrawSendsItsOwnerOptions()
    {
        var server = new FakeServer().Answer("POST /readings/generate", "reading-celtic-cross.json", HttpStatusCode.Created);
        using var api = server.Client(new MemoryCredentials(AccountToken, linkedName: "Mira Ashgrove"));

        await api.GenerateAsync(
            new GenerateRequest { DeckId = 4, SpreadId = 1, ReadingName = "Before the move", HideUser = true, Password = "lease" },
            Ct);

        var body = Assert.Single(server.Requests).Json;
        Assert.Equal(1, body.GetProperty("spread_id").GetInt32());
        Assert.Equal("Before the move", body.GetProperty("reading_name").GetString());
        Assert.True(body.GetProperty("hide_user").GetBoolean());
        Assert.Equal("lease", body.GetProperty("password").GetString());
    }

    [Fact]
    public async Task ACardReportIsSentInSnakeCase()
    {
        var server = new FakeServer().Answer("POST /card-reports", "card-report.json", HttpStatusCode.Created);
        using var api = server.Client(new MemoryCredentials());

        var status = await api.ReportCardAsync(1, 39, "Three of Cups", "cropping", "The bottom border is cut off", Ct);

        Assert.Equal("reported", status);
        var body = Assert.Single(server.Requests).Json;
        Assert.Equal(["deck_id", "card_id", "card_name", "reason", "details"], body.EnumerateObject().Select(p => p.Name));
        Assert.Equal("cropping", body.GetProperty("reason").GetString());
    }

    [Fact]
    public async Task TheLinkCodeIsExchangedWithNoCredential()
    {
        var server = new FakeServer().Answer("POST /plugin/token", "token.json");
        using var api = server.Client(new MemoryCredentials(drawKey: DrawKey));

        var grant = await api.ExchangeAccountCodeAsync("one-time-code", "the-verifier", Ct);

        Assert.Equal("tg_pat_5d1f0c3a9b7e42c68f2a1e0d9c4b7a63", grant?.Token);
        Assert.Equal("Mira Ashgrove", grant?.DisplayName);
        var sent = Assert.Single(server.Requests);
        Assert.Null(sent.Authorization);
        Assert.Equal("""{"code":"one-time-code","code_verifier":"the-verifier"}""", sent.Body);
    }

    [Fact]
    public async Task TheDrawKeyCodeIsExchangedWithNoCredential()
    {
        var server = new FakeServer().Answer("POST /plugin/draw-keys/token", "draw-key.json");
        using var api = server.Client(new MemoryCredentials(AccountToken, linkedName: "Mira Ashgrove"));

        var grant = await api.ExchangeDrawKeyCodeAsync("one-time-code", "the-verifier", Ct);

        Assert.Equal("tg_dk_8e2b7c41a9d05f36e1c4b9a72d0f5e18", grant?.Key);
        var sent = Assert.Single(server.Requests);
        Assert.Null(sent.Authorization);
        Assert.Equal("""{"code":"one-time-code","code_verifier":"the-verifier"}""", sent.Body);
    }

    [Fact]
    public async Task MyReadingsAreReadAsTheLinkedAccount()
    {
        var server = new FakeServer().Answer("GET /account/readings", "account-readings.json");
        using var api = server.Client(new MemoryCredentials(AccountToken, linkedName: "Mira Ashgrove"));

        var readings = await api.GetMyReadingsAsync(Ct);

        Assert.Equal(["b7e01d93c4", "e2a94f0b71", "71d3c09e5a", "9a0f61be28"], readings!.Select(r => r.ReadingId));
        Assert.Equal("Bearer " + AccountToken, Assert.Single(server.Requests).Authorization);
    }

    [Theory]
    [InlineData("4F9C2A7B1E", "4f9c2a7b1e")]
    [InlineData("  4f9c2a7b1e  ", "4f9c2a7b1e")]
    [InlineData("https://tarotgen.io/reading/4f9c2a7b1e", "4f9c2a7b1e")]
    [InlineData("https://tarotgen.io/reading/4F9C2A7B1E/?utm_source=chat#cards", "4f9c2a7b1e")]
    [InlineData("tarotgen.io/Reading/4f9c2a7b1e", "4f9c2a7b1e")]
    [InlineData("some-code", "some-code")]
    [InlineData("", null)]
    [InlineData("   ", null)]
    [InlineData("https://tarotgen.io/reading/", null)]
    public void AReadingIdIsReadFromACodeOrAShareLink(string typed, string? id) =>
        Assert.Equal(id, TarotApiClient.ExtractReadingId(typed));

    [Fact]
    public void TheSiteUrlIsKeptWithoutATrailingSlash()
    {
        using var api = new TarotApiClient(new HttpClient(), new MemoryCredentials(), "https://tarotgen.test/", _ => { });
        Assert.Equal("https://tarotgen.test", api.SiteUrl);
    }
}

public class ApiModelTests
{
    [Fact]
    public void ASpreadReadingReadsWhole()
    {
        var reading = FakeServer.Fixture<Reading>("reading-spread.json");

        Assert.Equal("4f9c2a7b1e", reading.ReadingId);
        Assert.Equal("Guest", reading.Reader);
        Assert.False(reading.Locked);
        Assert.Equal("2026-11-14 21:07:33", reading.ReadingTime);
        var info = reading.ReadingInfo!;
        Assert.Equal(1, info.DeckId);
        Assert.Equal("generated", info.Origin);
        Assert.Equal(["The Star", "Three of Cups", "The Magician"], info.Draw.Select(c => c.CardName));
        Assert.Equal([false, true, false], info.Draw.Select(c => c.Reversed));
        var spread = info.Spread!;
        Assert.Equal("Past, Present, Future", spread.Name);
        Assert.Equal(["What has led here", "Where things stand now", ""], spread.Positions.Select(p => p.Description));
        Assert.Equal(75, spread.Positions[2].X);
    }

    [Fact]
    public void AnOwnersReadingReadsAndItsJournalFieldsArePassedOver()
    {
        var reading = FakeServer.Fixture<Reading>("reading-celtic-cross.json");

        Assert.True(reading.IsOwner);
        Assert.True(reading.IsFinal);
        Assert.Equal("Before the move", reading.ReadingName);
        Assert.Equal(90, reading.ReadingInfo!.Spread!.Positions[1].Rotation);
        // An extra card nobody has named yet comes with no name.
        Assert.Equal(string.Empty, reading.ReadingInfo.Draw[9].CardName);
    }

    [Fact]
    public void AFreeDrawHasNoSpread()
    {
        var reading = FakeServer.Fixture<Reading>("reading-free.json");

        Assert.Null(reading.ReadingInfo!.Spread);
        Assert.Equal(5, reading.ReadingInfo.Draw.Count);
    }

    [Fact]
    public void ALockedReadingHasOnlyItsName()
    {
        var reading = FakeServer.Fixture<Reading>("reading-locked.json");

        Assert.True(reading.Locked);
        Assert.Equal("For Sable", reading.ReadingName);
        Assert.Null(reading.ReadingInfo);
    }

    [Fact]
    public void DecksReadWithTheirSystem()
    {
        var gilded = FakeServer.Fixture<List<Deck>>("decks.json").Single(d => d.DeckId == 4);

        Assert.Equal("Tomas Brierly", gilded.Artist);
        Assert.Equal(2, gilded.AdditionalCards);
        Assert.Equal(8.8, gilded.CardAspectH);
        Assert.Equal("RWS", gilded.SystemShortName);
        Assert.Equal(78, gilded.SystemTotalCards);
    }

    [Fact]
    public void SpreadsReadWithTheirCategory()
    {
        var paths = FakeServer.Fixture<List<Spread>>("spreads.json").Single(s => s.SpreadId == 6);

        Assert.Equal("Decisions", paths.CategoryName);
        Assert.Equal(4, paths.CategorySortOrder);
        Assert.Equal([0, 345, 15], paths.Positions.Select(p => p.Rotation));
    }

    [Fact]
    public void ASpreadFromBeforeCategoriesReadsWithNone()
    {
        var spread = JsonSerializer.Deserialize<Spread>(
            """{"spread_id":2,"name":"One Card","description":"","card_count":1,"positions":[{"order":1,"title":"Today","x":50,"y":50,"rotation":0}]}""",
            TarotApiClient.Json)!;

        Assert.Equal(string.Empty, spread.CategoryName);
        Assert.Equal(0, spread.CategorySortOrder);
        Assert.Equal(string.Empty, spread.Positions[0].Description);
    }

    [Fact]
    public void OnlyOtherNeedsDetails()
    {
        Assert.Equal(["artefact", "cropping", "quality", "wrong_card", "other"], CardReportReason.All.Select(r => r.Key));
        Assert.Equal(["other"], CardReportReason.All.Where(r => r.RequiresDetails).Select(r => r.Key));
    }
}
