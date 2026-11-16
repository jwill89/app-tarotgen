namespace TarotGen.Plugin.Core;

/// <summary>
/// Gets this install a draw key, which lets it draw readings without a linked
/// account: the site asks a signed-out draw for a Turnstile challenge, which a
/// game client can't show, so the player passes one once on the site's
/// /draw-key page (<see cref="LoopbackCodeFlow"/>), and the code it sends back is
/// exchanged for the key via POST /api/plugin/draw-keys/token. The key is kept
/// by the <see cref="ICredentialStore"/>, apart from the account token.
/// </summary>
public sealed class DrawKeyService : IDisposable
{
    // Longer than the account link's: the page has a challenge to solve first.
    private static readonly TimeSpan BrowserTimeout = TimeSpan.FromMinutes(5);

    private readonly TarotApiClient api;
    private readonly ICredentialStore credentials;
    private readonly Action<string> openBrowser;
    private readonly Action<string> warn;

    private CancellationTokenSource? flowCts;

    /// <param name="openBrowser">Opens a URL in the player's browser.</param>
    /// <param name="warn">Where a failed attempt is logged.</param>
    public DrawKeyService(TarotApiClient api, ICredentialStore credentials, Action<string> openBrowser, Action<string> warn)
    {
        this.api = api;
        this.credentials = credentials;
        this.openBrowser = openBrowser;
        this.warn = warn;
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
                this.api.SiteUrl + "/draw-key",
                string.Empty,
                "TarotGen: the plugin has its draw key. You can close this tab and return to the game.",
                BrowserTimeout,
                this.openBrowser,
                cts.Token).ConfigureAwait(false);
            if (returned is null)
            {
                this.Status = cts.IsCancellationRequested ? "Cancelled." : "Timed out — try again.";
                return;
            }

            var grant = await this.api.ExchangeDrawKeyCodeAsync(returned.Code, returned.Verifier, cts.Token)
                .ConfigureAwait(false);
            if (grant?.Key is not { Length: > 0 } key)
                throw new InvalidOperationException("The server did not return a key.");

            this.credentials.SaveDrawKey(key);
            this.Status = null;
        }
        catch (Exception ex)
        {
            // A Cancel() surfaces here (the listener was stopped) — report it as such.
            this.Status = cts.IsCancellationRequested ? "Cancelled." : $"Couldn't get a draw key: {ex.Message}";
            if (!cts.IsCancellationRequested)
                this.warn($"Getting a draw key failed: {ex.Message}");
        }
        finally
        {
            this.flowCts = null;
            cts.Dispose();
            this.IsBusy = false;
        }
    }
}
