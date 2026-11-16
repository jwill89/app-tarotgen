using System.Net;
using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

public class LoopbackCodeFlowTests
{
    private static CancellationToken Ct => TestContext.Current.CancellationToken;

    [Fact]
    public void TheChallengeIsS256OfTheVerifier() =>
        // RFC 7636, appendix B.
        Assert.Equal(
            "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM",
            LoopbackCodeFlow.Challenge("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"));

    [Fact]
    public async Task ThePageIsAskedForACodeSentBackToThisMachine()
    {
        using var browser = new FakeBrowser();
        var flow = LoopbackCodeFlow.RunAsync(
            "https://tarotgen.test/authorize", "client_id=plugin&", "Linked.", TimeSpan.FromSeconds(10), browser.Open, Ct);

        var page = await browser.Opened;
        var asked = await browser.Asked();
        Assert.Equal("https://tarotgen.test/authorize", page.GetLeftPart(UriPartial.Path));
        Assert.Equal("plugin", asked["client_id"]);
        Assert.Equal("S256", asked["code_challenge_method"]);
        Assert.Matches(@"^http://127\.0\.0\.1:\d+/callback$", asked["redirect_uri"]);
        Assert.False(string.IsNullOrEmpty(asked["state"]));

        var answered = await browser.ComeBack("one-time-code");
        var returned = await flow;

        Assert.Contains("Linked.", answered, StringComparison.Ordinal);
        Assert.Equal("one-time-code", returned?.Code);
        Assert.Equal(asked["code_challenge"], LoopbackCodeFlow.Challenge(returned!.Verifier));
    }

    [Fact]
    public async Task ACodeForAnotherRequestIsRefused()
    {
        using var browser = new FakeBrowser();
        var flow = LoopbackCodeFlow.RunAsync(
            "https://tarotgen.test/draw-key", string.Empty, "Done.", TimeSpan.FromSeconds(10), browser.Open, Ct);

        var answered = await browser.ComeBack("one-time-code", state: "someone-elses-state");

        Assert.Contains("cancelled", answered, StringComparison.Ordinal);
        await Assert.ThrowsAsync<InvalidOperationException>(() => flow);
    }

    [Fact]
    public async Task ComingBackWithoutACodeIsRefused()
    {
        using var browser = new FakeBrowser();
        var flow = LoopbackCodeFlow.RunAsync(
            "https://tarotgen.test/authorize", string.Empty, "Done.", TimeSpan.FromSeconds(10), browser.Open, Ct);

        await browser.ComeBack(code: null);

        await Assert.ThrowsAsync<InvalidOperationException>(() => flow);
    }

    [Fact]
    public async Task ABrowserThatNeverComesBackTimesOut()
    {
        using var browser = new FakeBrowser();

        var returned = await LoopbackCodeFlow.RunAsync(
            "https://tarotgen.test/authorize", string.Empty, "Done.", TimeSpan.FromMilliseconds(200), browser.Open, Ct);

        Assert.Null(returned);
    }
}

public class LinkServiceTests
{
    [Fact]
    public async Task LinkingKeepsTheAccountTokenTheCodeIsExchangedFor()
    {
        var credentials = new MemoryCredentials(drawKey: "tg_dk_kept");
        var server = new FakeServer().Answer("POST /plugin/token", "token.json");
        using var api = server.Client(credentials);
        using var browser = new FakeBrowser();
        using var link = new LinkService(api, credentials, browser.Open, _ => { });

        link.StartLink();
        Assert.True(link.IsBusy);
        var asked = await browser.Asked();
        Assert.Equal("https://tarotgen.test/authorize", (await browser.Opened).GetLeftPart(UriPartial.Path));
        Assert.Equal(("code", "plugin", "account"), (asked["response_type"], asked["client_id"], asked["scope"]));
        await browser.ComeBack("link-code");
        await Wait.Until(() => !link.IsBusy, "the link finished");

        Assert.Equal("tg_pat_5d1f0c3a9b7e42c68f2a1e0d9c4b7a63", credentials.Token);
        Assert.Equal("Mira Ashgrove", credentials.LinkedName);
        Assert.Equal("tg_dk_kept", credentials.DrawKey);
        Assert.Equal("Linked as Mira Ashgrove", link.Status);
        var exchange = Assert.Single(server.Requests);
        Assert.Equal("link-code", exchange.Json.GetProperty("code").GetString());
        Assert.Equal(asked["code_challenge"], LoopbackCodeFlow.Challenge(exchange.Json.GetProperty("code_verifier").GetString()!));
    }

    [Fact]
    public async Task ARefusedExchangeSaysWhy()
    {
        var credentials = new MemoryCredentials();
        var warnings = new List<string>();
        var server = new FakeServer().AnswerWith(
            "POST /plugin/token",
            HttpStatusCode.BadRequest,
            """{"type":"about:blank","title":"Bad Request","status":400,"detail":"This link code has expired. Try linking again."}""");
        using var api = server.Client(credentials);
        using var browser = new FakeBrowser();
        using var link = new LinkService(api, credentials, browser.Open, warnings.Add);

        link.StartLink();
        await browser.ComeBack("stale-code");
        await Wait.Until(() => !link.IsBusy, "the link finished");

        Assert.Equal("Link failed: This link code has expired. Try linking again.", link.Status);
        Assert.Null(credentials.Token);
        Assert.Equal("Account link failed: This link code has expired. Try linking again.", Assert.Single(warnings));
    }

    [Fact]
    public async Task ACancelledLinkSaysSoAndLogsNothing()
    {
        var credentials = new MemoryCredentials();
        var warnings = new List<string>();
        using var api = new FakeServer().Client(credentials);
        using var browser = new FakeBrowser();
        using var link = new LinkService(api, credentials, browser.Open, warnings.Add);

        link.StartLink();
        await browser.Opened;
        link.Cancel();
        await Wait.Until(() => !link.IsBusy, "the link stopped");

        Assert.Equal("Link cancelled.", link.Status);
        Assert.Empty(warnings);
    }

    [Fact]
    public void UnlinkingForgetsTheAccountAndKeepsTheDrawKey()
    {
        var credentials = new MemoryCredentials("tg_pat_old", "tg_dk_kept", "Mira Ashgrove");
        using var api = new FakeServer().Client(credentials);
        using var link = new LinkService(api, credentials, _ => { }, _ => { });

        link.Unlink();

        Assert.Null(credentials.Token);
        Assert.Equal(string.Empty, credentials.LinkedName);
        Assert.Equal("tg_dk_kept", credentials.DrawKey);
        Assert.Null(link.Status);
    }
}

public class DrawKeyServiceTests
{
    [Fact]
    public async Task GettingAKeyKeepsTheKeyTheCodeIsExchangedFor()
    {
        var credentials = new MemoryCredentials();
        var server = new FakeServer().Answer("POST /plugin/draw-keys/token", "draw-key.json");
        using var api = server.Client(credentials);
        using var browser = new FakeBrowser();
        using var keys = new DrawKeyService(api, credentials, browser.Open, _ => { });

        keys.StartGetKey();
        Assert.Equal("Waiting for you in your browser…", keys.Status);
        Assert.Equal("https://tarotgen.test/draw-key", (await browser.Opened).GetLeftPart(UriPartial.Path));
        await browser.ComeBack("key-code");
        await Wait.Until(() => !keys.IsBusy, "the key arrived");

        Assert.Equal("tg_dk_8e2b7c41a9d05f36e1c4b9a72d0f5e18", credentials.DrawKey);
        Assert.Null(keys.Status);
        Assert.True(api.CanDraw);
    }

    [Fact]
    public async Task AnAnswerWithNoKeyIsAFailure()
    {
        var credentials = new MemoryCredentials();
        var server = new FakeServer().AnswerWith("POST /plugin/draw-keys/token", HttpStatusCode.OK, "{}");
        using var api = server.Client(credentials);
        using var browser = new FakeBrowser();
        using var keys = new DrawKeyService(api, credentials, browser.Open, _ => { });

        keys.StartGetKey();
        await browser.ComeBack("key-code");
        await Wait.Until(() => !keys.IsBusy, "the attempt finished");

        Assert.Equal("Couldn't get a draw key: The server did not return a key.", keys.Status);
        Assert.Null(credentials.DrawKey);
    }

    [Fact]
    public async Task ARefusedExchangeSaysWhy()
    {
        var credentials = new MemoryCredentials();
        var server = new FakeServer().AnswerWith(
            "POST /plugin/draw-keys/token",
            HttpStatusCode.TooManyRequests,
            """{"type":"about:blank","title":"Too Many Requests","status":429,"detail":"Too many attempts. Please wait a minute and try again."}""");
        using var api = server.Client(credentials);
        using var browser = new FakeBrowser();
        using var keys = new DrawKeyService(api, credentials, browser.Open, _ => { });

        keys.StartGetKey();
        await browser.ComeBack("key-code");
        await Wait.Until(() => !keys.IsBusy, "the attempt finished");

        Assert.Equal("Couldn't get a draw key: Too many attempts. Please wait a minute and try again.", keys.Status);
    }
}
