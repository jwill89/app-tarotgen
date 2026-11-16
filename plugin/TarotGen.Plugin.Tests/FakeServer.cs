using System.Collections.Concurrent;
using System.Net;
using System.Text;
using System.Text.Json;
using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

/// <summary>One request as the client sent it: read here, since the client lets go of a
/// body once it has its answer.</summary>
internal sealed record Sent(string Method, string Path, string? Authorization, string? Body)
{
    /// <summary>The body's JSON object.</summary>
    public JsonElement Json => JsonDocument.Parse(this.Body ?? "null").RootElement;
}

/// <summary>
/// A pretend TarotGen site: canned answers by method and path under /api, taken from the
/// fixtures, and every request kept. Anything not answered is a 404 problem, as the real
/// API sends one.
/// </summary>
internal sealed class FakeServer : HttpMessageHandler
{
    public const string Site = "https://tarotgen.test";

    private readonly ConcurrentDictionary<string, (HttpStatusCode Status, string Body, string Type)> answers = new();

    public ConcurrentQueue<Sent> Requests { get; } = new();

    /// <summary>Runs as each request arrives, before it is answered.</summary>
    public Action? OnRequest { get; set; }

    /// <summary>Answers <paramref name="route"/> ("GET /decks") with a fixture: a JSON body
    /// below 400, a problem details body from 400.</summary>
    public FakeServer Answer(string route, string fixture, HttpStatusCode status = HttpStatusCode.OK)
        => this.AnswerWith(route, status, Fixture(fixture));

    public FakeServer AnswerWith(string route, HttpStatusCode status, string body, string? contentType = null)
    {
        this.answers[route] = (status, body, contentType ?? ((int)status < 400 ? "application/json" : "application/problem+json"));
        return this;
    }

    /// <summary>A client of this server, holding <paramref name="credentials"/>; what it logs goes
    /// to <paramref name="warnings"/>.</summary>
    public TarotApiClient Client(ICredentialStore credentials, ICollection<string>? warnings = null)
        => new(new HttpClient(this), credentials, Site, message => warnings?.Add(message));

    public static string Fixture(string name) => File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Fixtures", name));

    /// <summary>A fixture as the client itself reads it.</summary>
    public static T Fixture<T>(string name) => JsonSerializer.Deserialize<T>(Fixture(name), TarotApiClient.Json)!;

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var path = request.RequestUri!.PathAndQuery;
        path = path.StartsWith("/api/", StringComparison.Ordinal) ? path["/api".Length..] : path;
        var body = request.Content is null ? null : await request.Content.ReadAsStringAsync(cancellationToken);
        this.Requests.Enqueue(new Sent(request.Method.Method, path, request.Headers.Authorization?.ToString(), body));
        this.OnRequest?.Invoke();

        var (status, answer, type) = this.answers.TryGetValue($"{request.Method.Method} {path}", out var found)
            ? found
            : (HttpStatusCode.NotFound,
                """{"type":"about:blank","title":"Not Found","status":404,"detail":"No such route."}""",
                "application/problem+json");
        return new HttpResponseMessage(status) { Content = new StringContent(answer, Encoding.UTF8, type) };
    }
}

internal static class Wait
{
    /// <summary>Waits for something a background task is about to make true.</summary>
    public static async Task Until(Func<bool> met, string what)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        while (!met())
        {
            if (DateTime.UtcNow > deadline)
                throw new TimeoutException($"never happened: {what}");
            await Task.Delay(10, TestContext.Current.CancellationToken);
        }
    }
}
