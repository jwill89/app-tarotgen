using System.Net.Http.Json;
using System.Text.Json.Serialization;
using Dalamud.Plugin.Services;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Drives the browser account-link flow: opens the site's /authorize page
/// (<see cref="LoopbackCodeFlow"/>), then exchanges the code for an account token
/// via POST /api/plugin/token.
/// </summary>
public sealed class LinkService : IDisposable
{
    private const int TimeoutMinutes = 3;

    private readonly HttpClient http;
    private readonly TokenStore tokens;
    private readonly IPluginLog log;

    private CancellationTokenSource? flowCts;

    public LinkService(HttpClient http, TokenStore tokens, IPluginLog log)
    {
        this.http = http;
        this.tokens = tokens;
        this.log = log;
    }

    /// <summary>True while a link attempt is in flight.</summary>
    public bool IsBusy { get; private set; }

    /// <summary>Last status/error message for the UI (may be null).</summary>
    public string? Status { get; private set; }

    /// <summary>Begin linking (non-blocking; opens the browser). No-op if already busy.</summary>
    public void StartLink()
    {
        if (this.IsBusy)
            return;

        this.IsBusy = true;
        this.Status = "Waiting for you to connect in your browser…";
        this.flowCts = new CancellationTokenSource();
        _ = Task.Run(this.RunAsync);
    }

    /// <summary>Abort an in-flight link attempt.</summary>
    public void Cancel() => this.flowCts?.Cancel();

    /// <summary>
    /// Stops an in-flight link when the plugin unloads. The flow's own
    /// <c>finally</c> releases the token source.
    /// </summary>
    public void Dispose() => this.Cancel();

    public void Unlink()
    {
        this.tokens.Clear();
        this.Status = null;
    }

    private async Task RunAsync()
    {
        var cts = this.flowCts!;
        try
        {
            var returned = await LoopbackCodeFlow.RunAsync(
                "/authorize",
                "response_type=code&client_id=plugin&scope=account&",
                "TarotGen linked. You can close this tab and return to the game.",
                TimeSpan.FromMinutes(TimeoutMinutes),
                cts.Token).ConfigureAwait(false);
            if (returned is null)
            {
                this.Status = cts.IsCancellationRequested ? "Link cancelled." : "Link timed out — try again.";
                return;
            }

            // Exchange the PKCE code for the account token.
            using var response = await this.http
                .PostAsJsonAsync(
                    $"{Configuration.SiteUrl}/api/plugin/token",
                    new { code = returned.Code, code_verifier = returned.Verifier })
                .ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                throw new InvalidOperationException($"Token exchange failed (HTTP {(int)response.StatusCode}).");

            var payload = await response.Content.ReadFromJsonAsync<TokenResponse>().ConfigureAwait(false);
            if (payload?.Token is not { Length: > 0 })
                throw new InvalidOperationException("The server did not return a token.");

            this.tokens.Save(payload.Token, payload.DisplayName ?? string.Empty);
            this.Status = $"Linked as {this.tokens.LinkedName}";
        }
        catch (Exception ex)
        {
            // A Cancel() surfaces here (the listener was stopped) — report it as such.
            this.Status = cts.IsCancellationRequested ? "Link cancelled." : $"Link failed: {ex.Message}";
            if (!cts.IsCancellationRequested)
                this.log.Warning($"Account link failed: {ex.Message}");
        }
        finally
        {
            this.flowCts = null;
            cts.Dispose();
            this.IsBusy = false;
        }
    }

    private sealed class TokenResponse
    {
        [JsonPropertyName("token")]
        public string? Token { get; set; }

        [JsonPropertyName("display_name")]
        public string? DisplayName { get; set; }
    }
}
