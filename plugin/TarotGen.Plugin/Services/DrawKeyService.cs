using System.Net.Http.Json;
using System.Text.Json.Serialization;
using Dalamud.Plugin.Services;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Gets this install a draw key, which lets it draw readings without a linked
/// account: the site asks a signed-out draw for a Turnstile challenge, which a
/// game client can't show, so the player passes one once on the site's
/// /draw-key page (<see cref="LoopbackCodeFlow"/>), and the code it sends back is
/// exchanged for the key via POST /api/plugin/draw-keys/token. The key is kept
/// DPAPI-encrypted by <see cref="TokenStore"/>, apart from the account token.
/// </summary>
public sealed class DrawKeyService : IDisposable
{
    // Longer than the account link's: the page has a challenge to solve first.
    private const int TimeoutMinutes = 5;

    private readonly HttpClient http;
    private readonly TokenStore tokens;
    private readonly IPluginLog log;

    private CancellationTokenSource? flowCts;

    public DrawKeyService(HttpClient http, TokenStore tokens, IPluginLog log)
    {
        this.http = http;
        this.tokens = tokens;
        this.log = log;
    }

    /// <summary>True while the player is getting a key in the browser.</summary>
    public bool IsBusy { get; private set; }

    /// <summary>Last status/error message for the UI (may be null).</summary>
    public string? Status { get; private set; }

    /// <summary>Begin getting a key (non-blocking; opens the browser). No-op if already busy.</summary>
    public void StartGetKey()
    {
        if (this.IsBusy)
            return;

        this.IsBusy = true;
        this.Status = "Waiting for you in your browser…";
        this.flowCts = new CancellationTokenSource();
        _ = Task.Run(this.RunAsync);
    }

    /// <summary>Abort an in-flight attempt.</summary>
    public void Cancel() => this.flowCts?.Cancel();

    /// <summary>Stops an in-flight attempt when the plugin unloads.</summary>
    public void Dispose() => this.Cancel();

    private async Task RunAsync()
    {
        var cts = this.flowCts!;
        try
        {
            var returned = await LoopbackCodeFlow.RunAsync(
                "/draw-key",
                string.Empty,
                "TarotGen: the plugin has its draw key. You can close this tab and return to the game.",
                TimeSpan.FromMinutes(TimeoutMinutes),
                cts.Token).ConfigureAwait(false);
            if (returned is null)
            {
                this.Status = cts.IsCancellationRequested ? "Cancelled." : "Timed out — try again.";
                return;
            }

            using var response = await this.http
                .PostAsJsonAsync(
                    $"{Configuration.SiteUrl}/api/plugin/draw-keys/token",
                    new { code = returned.Code, code_verifier = returned.Verifier })
                .ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                throw new InvalidOperationException($"The key exchange failed (HTTP {(int)response.StatusCode}).");

            var payload = await response.Content.ReadFromJsonAsync<KeyResponse>().ConfigureAwait(false);
            if (payload?.Key is not { Length: > 0 })
                throw new InvalidOperationException("The server did not return a key.");

            this.tokens.SaveDrawKey(payload.Key);
            this.Status = null;
        }
        catch (Exception ex)
        {
            // A Cancel() surfaces here (the listener was stopped) — report it as such.
            this.Status = cts.IsCancellationRequested ? "Cancelled." : $"Couldn't get a draw key: {ex.Message}";
            if (!cts.IsCancellationRequested)
                this.log.Warning($"Getting a draw key failed: {ex.Message}");
        }
        finally
        {
            this.flowCts = null;
            cts.Dispose();
            this.IsBusy = false;
        }
    }

    private sealed class KeyResponse
    {
        [JsonPropertyName("key")]
        public string? Key { get; set; }
    }
}
