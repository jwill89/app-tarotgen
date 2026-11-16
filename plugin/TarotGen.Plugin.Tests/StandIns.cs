using System.Collections.Specialized;
using System.Web;
using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

/// <summary>The credentials held in memory, as the plugin's DPAPI store holds them on disk.</summary>
internal sealed class MemoryCredentials : ICredentialStore
{
    public MemoryCredentials(string? token = null, string? drawKey = null, string linkedName = "")
    {
        this.Token = token;
        this.DrawKey = drawKey;
        this.LinkedName = token is null ? string.Empty : linkedName;
    }

    public string? Token { get; private set; }

    public string? DrawKey { get; private set; }

    public string LinkedName { get; private set; }

    public void Save(string token, string displayName) => (this.Token, this.LinkedName) = (token, displayName);

    public void Clear() => (this.Token, this.LinkedName) = (null, string.Empty);

    public void SaveDrawKey(string key) => this.DrawKey = key;

    public void ClearDrawKey() => this.DrawKey = null;
}

/// <summary>
/// The player's browser: it is handed the site page the plugin opens, and comes back to the
/// plugin's loopback listener as the site page would redirect it.
/// </summary>
internal sealed class FakeBrowser : IDisposable
{
    private readonly HttpClient http = new();
    private readonly TaskCompletionSource<Uri> opened = new(TaskCreationOptions.RunContinuationsAsynchronously);

    /// <summary>The page the plugin opened, once it has.</summary>
    public Task<Uri> Opened => this.opened.Task;

    public void Open(string url) => this.opened.TrySetResult(new Uri(url));

    /// <summary>The opened page's query.</summary>
    public async Task<NameValueCollection> Asked() => HttpUtility.ParseQueryString((await this.Opened).Query);

    /// <summary>Redirects to the plugin with <paramref name="code"/> (none: the player said no),
    /// and the state it was given unless another is named. Returns the page the plugin answered.</summary>
    public async Task<string> ComeBack(string? code, string? state = null)
    {
        var asked = await this.Asked();
        var query = code is null ? "error=access_denied" : $"code={Uri.EscapeDataString(code)}";
        query += $"&state={Uri.EscapeDataString(state ?? asked["state"]!)}";
        return await this.http.GetStringAsync($"{asked["redirect_uri"]}?{query}", TestContext.Current.CancellationToken);
    }

    public void Dispose() => this.http.Dispose();
}
