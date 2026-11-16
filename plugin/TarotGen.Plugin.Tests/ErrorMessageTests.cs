using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

public class ErrorMessageTests
{
    [Fact]
    public void AProblemsDetailIsTheMessage()
    {
        var body = FakeServer.Fixture("problem-draw-key-revoked.json");
        Assert.Equal("This draw key is no longer valid. Get a new one from the plugin.", ErrorMessage.From(401, body));
    }

    [Fact]
    public void TheDetailIsPreferredToTheFieldErrors()
    {
        const string body = """
            {"type":"about:blank","title":"Unprocessable Content","status":422,"detail":"Check the highlighted fields.",
             "errors":[{"message":"Pick a deck.","location":"body.deck_id"}]}
            """;
        Assert.Equal("Check the highlighted fields.", ErrorMessage.From(422, body));
    }

    [Theory]
    [InlineData("""{"title":"Unprocessable Content","status":422,"errors":[{"message":"Pick a deck.","location":"body.deck_id"},{"message":"Choose 1 to 78 cards.","location":"body.number_of_cards"}]}""")]
    [InlineData("""{"title":"Unprocessable Content","status":422,"detail":"  ","errors":[{"message":"Pick a deck.","location":"body.deck_id"},{"message":"Choose 1 to 78 cards.","location":"body.number_of_cards"}]}""")]
    [InlineData("""{"title":"Unprocessable Content","status":422,"errors":[{"message":"Pick a deck."},{"location":"body.spread_id"},{"message":"Choose 1 to 78 cards."}]}""")]
    public void WithoutADetailTheFieldErrorsAreJoined(string body) =>
        Assert.Equal("Pick a deck.; Choose 1 to 78 cards.", ErrorMessage.From(422, body));

    [Fact]
    public void AnOlderServersErrorIsTheMessage() =>
        Assert.Equal("Invalid deck.", ErrorMessage.From(400, """{"error":"Invalid deck."}"""));

    [Fact]
    public void AnOlderServersErrorListIsJoined() =>
        Assert.Equal("Email is required.; Password is too short.", ErrorMessage.From(400, """{"errors":["Email is required.","Password is too short."]}"""));

    [Fact]
    public void AnOlderServersErrorComesBeforeItsList() =>
        Assert.Equal("Invalid draw.", ErrorMessage.From(400, """{"error":"Invalid draw.","errors":["Unknown spread."]}"""));

    [Fact]
    public void ProblemFieldErrorsComeBeforeAnOlderError() =>
        Assert.Equal("Pick a deck.", ErrorMessage.From(422, """{"error":"Invalid draw.","errors":[{"message":"Pick a deck.","location":"body.deck_id"}]}"""));

    [Theory]
    [InlineData(502, "<html><body><h1>502 Bad Gateway</h1></body></html>")]
    [InlineData(500, "")]
    [InlineData(503, null)]
    [InlineData(400, "[\"not an object\"]")]
    [InlineData(400, "\"just a string\"")]
    [InlineData(404, """{"type":"about:blank","title":"Not Found","status":404}""")]
    [InlineData(422, """{"detail":42,"errors":[{"location":"body.deck_id"}]}""")]
    [InlineData(400, """{"error":"   ","errors":[]}""")]
    public void ABodyWithNoMessageReadsAsItsStatus(int status, string? body) =>
        Assert.Equal($"HTTP {status}", ErrorMessage.From(status, body));
}
