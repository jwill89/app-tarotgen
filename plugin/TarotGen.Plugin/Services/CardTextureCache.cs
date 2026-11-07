using System.Collections.Concurrent;
using Dalamud.Interface.Textures.TextureWraps;
using Dalamud.Plugin.Services;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Downloads card art from the site's static <c>/assets/decks/…</c> tree, loads
/// it into ImGui textures via <see cref="ITextureProvider"/>, and caches the
/// results in memory (owned wraps, disposed on unload) plus on disk so immutable
/// art is fetched at most once. All texture creation happens off the framework
/// thread; <see cref="GetCard"/>/<see cref="GetCardBack"/> return null until a
/// load finishes, so <c>Draw()</c> shows a placeholder meanwhile.
/// </summary>
public sealed class CardTextureCache : IDisposable
{
    private readonly HttpClient http;
    private readonly ITextureProvider textures;
    private readonly IPluginLog log;
    private readonly string diskDir;
    private readonly CancellationTokenSource cts = new();

    private readonly ConcurrentDictionary<string, IDalamudTextureWrap> cache = new();
    private readonly HashSet<string> inflight = new();
    private readonly HashSet<string> failed = new();
    private readonly object gate = new();

    public CardTextureCache(HttpClient http, ITextureProvider textures, IPluginLog log, string diskDir)
    {
        this.http = http;
        this.textures = textures;
        this.log = log;
        this.diskDir = diskDir;
        try { Directory.CreateDirectory(diskDir); }
        catch (Exception ex) { this.log.Warning($"Couldn't create card cache dir: {ex.Message}"); }
    }

    private static string SiteBase => Configuration.SiteUrl;

    /// <summary>The full-res face texture for a card, or null while it loads.</summary>
    public IDalamudTextureWrap? GetCard(int deckId, int cardId) => this.Get(
        key: $"{deckId}_{cardId:0000}",
        url: $"{SiteBase}/assets/decks/{deckId}/Card_{cardId:0000}.png");

    /// <summary>The card-back texture for a deck, or null while it loads.</summary>
    public IDalamudTextureWrap? GetCardBack(int deckId) => this.Get(
        key: $"{deckId}_back",
        url: $"{SiteBase}/assets/decks/{deckId}/Card_Back.png");

    private IDalamudTextureWrap? Get(string key, string url)
    {
        if (this.cache.TryGetValue(key, out var wrap))
            return wrap;

        lock (this.gate)
        {
            if (this.failed.Contains(key) || this.inflight.Contains(key))
                return null;
            this.inflight.Add(key);
        }

        _ = Task.Run(() => this.LoadAsync(key, url));
        return null;
    }

    private async Task LoadAsync(string key, string url)
    {
        try
        {
            var file = Path.Combine(this.diskDir, key + ".png");
            byte[] bytes;
            if (TryReadFile(file, out var cached))
            {
                bytes = cached;
            }
            else
            {
                bytes = await this.http.GetByteArrayAsync(url, this.cts.Token).ConfigureAwait(false);
                this.TryWriteFile(file, bytes);
            }

            // ITextureProvider.CreateFromImageAsync(ReadOnlyMemory<byte>, string? debugName, CancellationToken)
            // -> Task<IDalamudTextureWrap>. This is the owned-texture path (we dispose the wrap).
            var texture = await this.textures.CreateFromImageAsync(bytes, key, this.cts.Token).ConfigureAwait(false);
            this.cache[key] = texture;
        }
        catch (OperationCanceledException)
        {
            // plugin unloading — ignore
        }
        catch (Exception ex)
        {
            this.log.Warning($"Card texture load failed for {key}: {ex.Message}");
            lock (this.gate) this.failed.Add(key);
        }
        finally
        {
            lock (this.gate) this.inflight.Remove(key);
        }
    }

    private static bool TryReadFile(string path, out byte[] bytes)
    {
        try
        {
            if (File.Exists(path))
            {
                bytes = File.ReadAllBytes(path);
                return bytes.Length > 0;
            }
        }
        catch
        {
            /* fall through to network */
        }

        bytes = Array.Empty<byte>();
        return false;
    }

    private void TryWriteFile(string path, byte[] bytes)
    {
        try { File.WriteAllBytes(path, bytes); }
        catch (Exception ex) { this.log.Debug($"Couldn't cache card to disk ({path}): {ex.Message}"); }
    }

    public void Dispose()
    {
        this.cts.Cancel();
        foreach (var wrap in this.cache.Values)
        {
            try { wrap.Dispose(); }
            catch { /* best-effort */ }
        }
        this.cache.Clear();
        this.cts.Dispose();
    }
}
