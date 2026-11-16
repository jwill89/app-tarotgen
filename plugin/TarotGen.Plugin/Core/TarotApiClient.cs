using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace TarotGen.Plugin.Core;

/// <summary>
/// Thin async wrapper over the TarotGen REST API. All calls run off the framework
/// thread; callers marshal results back before touching ImGui. A linked account's
/// Bearer token (from the <see cref="ICredentialStore"/>) is attached to every request — the
/// server treats it as optional on public reads and required on account routes.
/// An unlinked install sends its draw key instead, on draws only: that is all the
/// key is for. Decks/spreads are cached after first fetch.
/// </summary>
public sealed class TarotApiClient : IDisposable
{
    /// <summary>How every body is read and written: the API's snake_case, nulls left out.</summary>
    internal static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };

    private readonly HttpClient http;
    private readonly ICredentialStore credentials;
    private readonly Action<string> warn;
    private readonly CancellationTokenSource cts = new();

    private IReadOnlyList<Deck>? deckCache;
    private IReadOnlyList<Spread>? spreadCache;
    private Dictionary<int, Deck> decksById = new();

    /// <param name="siteUrl">The site's origin: REST calls go to <c>{siteUrl}/api</c>.</param>
    /// <param name="warn">Where a failed call is logged.</param>
    public TarotApiClient(HttpClient http, ICredentialStore credentials, string siteUrl, Action<string> warn)
    {
        this.http = http;
        this.credentials = credentials;
        this.SiteUrl = siteUrl.TrimEnd('/');
        this.warn = warn;
    }

    /// <summary>The site's origin, with no trailing slash.</summary>
    public string SiteUrl { get; }

    /// <summary>The plugin's linked cancellation token (cancelled on dispose).</summary>
    public CancellationToken Token => this.cts.Token;

    /// <summary>An account token is present (unlocks finalize / favorites / My Readings).</summary>
    public bool IsLinked => !string.IsNullOrEmpty(this.credentials.Token);

    /// <summary>A draw key is present: an unlinked install can draw.</summary>
    public bool HasDrawKey => !string.IsNullOrEmpty(this.credentials.DrawKey);

    /// <summary>
    /// The site lets this install draw: a linked account, or a draw key. Without
    /// either, the New Reading tab offers to get a key instead of drawing.
    /// </summary>
    public bool CanDraw => this.IsLinked || this.HasDrawKey;

    public string LinkedName => this.credentials.LinkedName;

    private string ApiBase => this.SiteUrl + "/api";

    // ── Catalog (cached) ─────────────────────────────────────────────────────

    public async Task<IReadOnlyList<Deck>> GetDecksAsync(CancellationToken ct = default)
    {
        if (this.deckCache is not null)
            return this.deckCache;

        var decks = await this.GetJsonAsync<List<Deck>>("/decks", ct).ConfigureAwait(false) ?? new();
        var byId = new Dictionary<int, Deck>();
        foreach (var d in decks)
            byId[d.DeckId] = d;
        this.decksById = byId;
        this.deckCache = decks;
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
        var credential = this.IsLinked ? this.credentials.Token
            : this.HasDrawKey ? this.credentials.DrawKey
            : null;
        try
        {
            return await this.PostJsonAsync<GenerateRequest, Reading>("/readings/generate", request, credential, ct)
                .ConfigureAwait(false);
        }
        catch (TarotApiException ex) when (ex.StatusCode == (int)HttpStatusCode.Unauthorized && credential is not null)
        {
            this.Forget(credential);
            throw;
        }
    }

    public Task<Reading?> GetReadingAsync(string readingId, CancellationToken ct = default)
        => this.GetJsonAsync<Reading>($"/readings/{Uri.EscapeDataString(readingId)}", ct);

    public Task<Reading?> UnlockReadingAsync(string readingId, string password, CancellationToken ct = default)
        => this.PostJsonAsync<object, Reading>(
            $"/readings/{Uri.EscapeDataString(readingId)}/unlock",
            new { Password = password },
            this.credentials.Token,
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
            this.credentials.Token,
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
            this.credentials.Token,
            ct);

    // ── The browser flows' code exchanges (no credential sent) ──────────────

    /// <summary>Swap the account link's one-time code and PKCE verifier for the account token.</summary>
    public Task<AccountTokenGrant?> ExchangeAccountCodeAsync(string code, string verifier, CancellationToken ct = default)
        => this.PostJsonAsync<object, AccountTokenGrant>(
            "/plugin/token", new { Code = code, CodeVerifier = verifier }, null, ct);

    /// <summary>Swap the draw-key page's one-time code and PKCE verifier for the draw key.</summary>
    public Task<DrawKeyGrant?> ExchangeDrawKeyCodeAsync(string code, string verifier, CancellationToken ct = default)
        => this.PostJsonAsync<object, DrawKeyGrant>(
            "/plugin/draw-keys/token", new { Code = code, CodeVerifier = verifier }, null, ct);

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

    // ── HTTP plumbing ────────────────────────────────────────────────────────

    /// <summary>
    /// The site refused <paramref name="credential"/> as no longer valid: forget it,
    /// if it is still the one held (a new one may have arrived meanwhile).
    /// </summary>
    private void Forget(string credential)
    {
        if (credential == this.credentials.Token)
            this.credentials.Clear();
        else if (credential == this.credentials.DrawKey)
            this.credentials.ClearDrawKey();
    }

    private async Task<T?> GetJsonAsync<T>(string path, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Get, this.ApiBase + path);
        AddAuth(request, this.credentials.Token);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await this.ReadOrThrow<T>(resp, linked.Token).ConfigureAwait(false);
    }

    private async Task<TOut?> PostJsonAsync<TIn, TOut>(string path, TIn body, string? bearer, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Post, this.ApiBase + path)
        {
            Content = JsonContent.Create(body, options: Json),
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
            return await resp.Content.ReadFromJsonAsync<T>(Json, ct).ConfigureAwait(false);

        var status = (int)resp.StatusCode;
        var body = await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        var message = ErrorMessage.From(status, body);
        this.warn($"TarotGen API {status}: {message}");
        throw new TarotApiException(status, message);
    }
}

/// <summary>Thrown when the API returns a non-2xx status; carries the server message.</summary>
public sealed class TarotApiException : Exception
{
    public int StatusCode { get; }

    public TarotApiException(int statusCode, string message) : base(message)
        => this.StatusCode = statusCode;
}
