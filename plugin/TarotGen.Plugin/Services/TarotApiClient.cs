using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.RegularExpressions;
using Dalamud.Plugin.Services;
using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Thin async wrapper over the TarotGen REST API. All calls run off the framework
/// thread; callers marshal results back before touching ImGui. A linked account's
/// Bearer token (from <see cref="TokenStore"/>) is attached to every request — the
/// server treats it as optional on public reads and required on account routes.
/// An unlinked install sends its draw key instead, on draws only: that is all the
/// key is for. Decks/spreads are cached after first fetch.
/// </summary>
public sealed class TarotApiClient : IDisposable
{
    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    private readonly HttpClient http;
    private readonly TokenStore tokens;
    private readonly IPluginLog log;
    private readonly CancellationTokenSource cts = new();

    private IReadOnlyList<Deck>? deckCache;
    private IReadOnlyList<Spread>? spreadCache;
    private Dictionary<int, Deck> decksById = new();

    public TarotApiClient(HttpClient http, TokenStore tokens, IPluginLog log)
    {
        this.http = http;
        this.tokens = tokens;
        this.log = log;
    }

    /// <summary>The plugin's linked cancellation token (cancelled on dispose).</summary>
    public CancellationToken Token => this.cts.Token;

    public bool IsLinked => this.tokens.IsLinked;

    /// <summary>
    /// The site lets this install draw: a linked account, or a draw key. Without
    /// either, the New Reading tab offers to get a key instead of drawing.
    /// </summary>
    public bool CanDraw => this.tokens.IsLinked || this.tokens.HasDrawKey;

    public bool HasDrawKey => this.tokens.HasDrawKey;

    public string LinkedName => this.tokens.LinkedName;

    private static string ApiBase => Configuration.SiteUrl + "/api";

    // ── Catalog (cached) ─────────────────────────────────────────────────────

    public async Task<IReadOnlyList<Deck>> GetDecksAsync(CancellationToken ct = default)
    {
        if (this.deckCache is not null)
            return this.deckCache;

        var decks = await this.GetJsonAsync<List<Deck>>("/decks", ct).ConfigureAwait(false) ?? new();
        this.deckCache = decks;
        this.decksById = new Dictionary<int, Deck>();
        foreach (var d in decks)
            this.decksById[d.DeckId] = d;
        return decks;
    }

    public async Task<IReadOnlyList<Spread>> GetSpreadsAsync(CancellationToken ct = default)
    {
        if (this.spreadCache is not null)
            return this.spreadCache;

        var spreads = await this.GetJsonAsync<List<Spread>>("/spreads", ct).ConfigureAwait(false) ?? new();
        this.spreadCache = spreads;
        return spreads;
    }

    /// <summary>A deck from the cache, or null if decks aren't loaded / unknown id.</summary>
    public Deck? DeckById(int id) => this.decksById.TryGetValue(id, out var d) ? d : null;

    // ── Readings (anonymous; token-aware) ────────────────────────────────────

    /// <summary>
    /// Draw a reading, as the linked account or with this install's draw key. A
    /// 401 means the site no longer takes the one sent (a revoked account link or
    /// draw key): it is forgotten, so the next draw asks for a new one, and the
    /// server's message is rethrown for the UI.
    /// </summary>
    public async Task<Reading?> GenerateAsync(GenerateRequest request, CancellationToken ct = default)
    {
        var credential = this.tokens.Token ?? this.tokens.DrawKey;
        try
        {
            return await this.PostJsonAsync<GenerateRequest, Reading>("/readings/generate", request, credential, ct)
                .ConfigureAwait(false);
        }
        catch (TarotApiException ex) when (ex.StatusCode == (int)HttpStatusCode.Unauthorized && credential is not null)
        {
            this.tokens.Forget(credential);
            throw;
        }
    }

    public Task<Reading?> GetReadingAsync(string readingId, CancellationToken ct = default)
        => this.GetJsonAsync<Reading>($"/readings/{Uri.EscapeDataString(readingId)}", ct);

    public Task<Reading?> UnlockReadingAsync(string readingId, string password, CancellationToken ct = default)
        => this.PostJsonAsync<object, Reading>(
            $"/readings/{Uri.EscapeDataString(readingId)}/unlock",
            new { password },
            ct);

    /// <summary>
    /// Report a problem with a card's image, with a <see cref="CardReportReason"/>
    /// key and optional details (required for "other"). Returns the server status
    /// ("reported" or "already_reported"); throws <see cref="TarotApiException"/>
    /// when the server rejects it (validation, or rate-limited with a 429).
    /// </summary>
    public async Task<string?> ReportCardAsync(
        int deckId, int cardId, string cardName, string reason, string details, CancellationToken ct = default)
    {
        var ack = await this.PostJsonAsync<object, CardReportAck>(
            "/card-reports",
            new { DeckId = deckId, CardId = cardId, CardName = cardName, Reason = reason, Details = details },
            ct).ConfigureAwait(false);
        return ack?.Status;
    }

    // ── Account (require a linked token) ─────────────────────────────────────

    public Task<List<Reading>?> GetMyReadingsAsync(CancellationToken ct = default)
        => this.GetJsonAsync<List<Reading>>("/account/readings", ct);

    public Task<Reading?> FinalizeReadingAsync(string readingId, CancellationToken ct = default)
        => this.PostJsonAsync<object, Reading>(
            $"/readings/{Uri.EscapeDataString(readingId)}/finalize",
            new { },
            ct);

    // ── HTTP plumbing ────────────────────────────────────────────────────────

    private async Task<T?> GetJsonAsync<T>(string path, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Get, ApiBase + path);
        AddAuth(request, this.tokens.Token);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await this.ReadOrThrow<T>(resp, linked.Token).ConfigureAwait(false);
    }

    private Task<TOut?> PostJsonAsync<TIn, TOut>(string path, TIn body, CancellationToken ct)
        => this.PostJsonAsync<TIn, TOut>(path, body, this.tokens.Token, ct);

    private async Task<TOut?> PostJsonAsync<TIn, TOut>(string path, TIn body, string? bearer, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Post, ApiBase + path)
        {
            Content = JsonContent.Create(body, options: JsonOpts),
        };
        AddAuth(request, bearer);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await this.ReadOrThrow<TOut>(resp, linked.Token).ConfigureAwait(false);
    }

    private static void AddAuth(HttpRequestMessage request, string? bearer)
    {
        if (!string.IsNullOrEmpty(bearer))
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearer);
    }

    private async Task<T?> ReadOrThrow<T>(HttpResponseMessage resp, CancellationToken ct)
    {
        if (resp.StatusCode == HttpStatusCode.NoContent)
            return default;

        if (resp.IsSuccessStatusCode)
            return await resp.Content.ReadFromJsonAsync<T>(JsonOpts, ct).ConfigureAwait(false);

        // The API returns { "error": "…" } (or { "errors": [...] }) on failure.
        var message = $"HTTP {(int)resp.StatusCode}";
        try
        {
            var body = await resp.Content.ReadFromJsonAsync<ApiError>(JsonOpts, ct).ConfigureAwait(false);
            if (!string.IsNullOrWhiteSpace(body?.Error))
                message = body!.Error!;
            else if (body?.Errors is { Count: > 0 })
                message = string.Join("; ", body.Errors);
        }
        catch
        {
            /* non-JSON error body — keep the status-code message */
        }

        this.log.Warning($"TarotGen API {(int)resp.StatusCode} for request: {message}");
        throw new TarotApiException((int)resp.StatusCode, message);
    }

    /// <summary>
    /// Pull a reading id out of a raw code or a share URL
    /// (e.g. https://tarotgen.io/reading/ab12cd34ef). reading_id is 10 hex chars.
    /// </summary>
    public static string? ExtractReadingId(string input)
    {
        if (string.IsNullOrWhiteSpace(input))
            return null;

        input = input.Trim();
        var idx = input.IndexOf("/reading/", StringComparison.OrdinalIgnoreCase);
        if (idx >= 0)
            input = input[(idx + "/reading/".Length)..];

        input = input.Split('?', '#')[0].Trim('/');
        var segment = input.Contains('/') ? input[(input.LastIndexOf('/') + 1)..] : input;

        return Regex.IsMatch(segment, "^[0-9a-fA-F]{6,}$") ? segment.ToLowerInvariant()
             : segment.Length > 0 ? segment
             : null;
    }

    public void Dispose()
    {
        this.cts.Cancel();
        this.cts.Dispose();
    }

    private sealed class ApiError
    {
        public string? Error { get; set; }
        public List<string>? Errors { get; set; }
    }
}

/// <summary>Thrown when the API returns a non-2xx status; carries the server message.</summary>
public sealed class TarotApiException : Exception
{
    public int StatusCode { get; }

    public TarotApiException(int statusCode, string message) : base(message)
        => this.StatusCode = statusCode;
}
