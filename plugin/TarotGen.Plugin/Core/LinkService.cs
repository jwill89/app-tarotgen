namespace TarotGen.Plugin.Core;

/// <summary>
/// Drives the browser account-link flow: opens the site's /authorize page
/// (<see cref="LoopbackCodeFlow"/>), then exchanges the code for an account token
/// via POST /api/plugin/token.
/// </summary>
public sealed class LinkService : IDisposable
{
    private static readonly TimeSpan BrowserTimeout = TimeSpan.FromMinutes(3);

    private readonly TarotApiClient api;
    private readonly ICredentialStore credentials;
    private readonly Action<string> openBrowser;
    private readonly Action<string> warn;

    private CancellationTokenSource? flowCts;

    /// <param name="openBrowser">Opens a URL in the player's browser.</param>
    /// <param name="warn">Where a failed link is logged.</param>
    public LinkService(TarotApiClient api, ICredentialStore credentials, Action<string> openBrowser, Action<string> warn)
    {
        this.api = api;
        this.credentials = credentials;
        this.openBrowser = openBrowser;
        this.warn = warn;
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
        this.credentials.Clear();
        this.Status = null;
    }

    private async Task RunAsync()
    {
        var cts = this.flowCts!;
        try
        {
            var returned = await LoopbackCodeFlow.RunAsync(
                this.api.SiteUrl + "/authorize",
                "response_type=code&client_id=plugin&scope=account&",
                "TarotGen linked. You can close this tab and return to the game.",
                BrowserTimeout,
                this.openBrowser,
                cts.Token).ConfigureAwait(false);
            if (returned is null)
            {
                this.Status = cts.IsCancellationRequested ? "Link cancelled." : "Link timed out — try again.";
                return;
            }

            var grant = await this.api.ExchangeAccountCodeAsync(returned.Code, returned.Verifier, cts.Token)
                .ConfigureAwait(false);
            if (grant?.Token is not { Length: > 0 } token)
                throw new InvalidOperationException("The server did not return a token.");

            this.credentials.Save(token, grant.DisplayName ?? string.Empty);
            this.Status = $"Linked as {this.credentials.LinkedName}";
        }
        catch (Exception ex)
        {
            // A Cancel() surfaces here (the listener was stopped) — report it as such.
            this.Status = cts.IsCancellationRequested ? "Link cancelled." : $"Link failed: {ex.Message}";
            if (!cts.IsCancellationRequested)
                this.warn($"Account link failed: {ex.Message}");
        }
        finally
        {
            this.flowCts = null;
            cts.Dispose();
            this.IsBusy = false;
        }
    }
}
