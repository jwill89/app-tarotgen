using System.Collections.Concurrent;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Drawing.Text;
using System.Net;
using System.Runtime.InteropServices;
using System.Text;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface.Textures.TextureWraps;
using TarotGen.Plugin.Core;
using TarotGen.Plugin.Windows;

namespace TarotGen.Plugin.Preview;

/// <summary>The credentials held in memory: a scene says which the player has.</summary>
internal sealed class Credentials : ICredentialStore
{
    public string? Token { get; private set; }

    public string? DrawKey { get; private set; }

    public string LinkedName { get; private set; } = string.Empty;

    public void Save(string token, string displayName) => (this.Token, this.LinkedName) = (token, displayName);

    public void Clear() => (this.Token, this.LinkedName) = (null, string.Empty);

    public void SaveDrawKey(string key) => this.DrawKey = key;

    public void ClearDrawKey() => this.DrawKey = null;
}

/// <summary>
/// The site the windows talk to: each route answers with a fixture, a JSON body below 400 and
/// a problem details body from 400. Anything else is the API's 404.
/// </summary>
internal sealed class FixtureSite : HttpMessageHandler
{
    private readonly string fixtures;
    private readonly ConcurrentDictionary<string, (HttpStatusCode Status, string Fixture)> answers = new();

    public FixtureSite(string fixtures)
    {
        this.fixtures = fixtures;
        this.Answer("GET /decks", "decks.json");
        this.Answer("GET /spreads", "spreads.json");
        this.Answer("GET /account/readings", "account-readings.json");
        this.Answer("GET /readings/4f9c2a7b1e", "reading-spread.json");
        this.Answer("GET /readings/b7e01d93c4", "reading-celtic-cross.json");
        this.Answer("GET /readings/0c55e8a2d6", "reading-free.json");
        this.Answer("GET /readings/5e3a0d7c92", "reading-locked.json");
        this.Answer("POST /readings/generate", "reading-spread.json", HttpStatusCode.Created);
        this.Answer("POST /card-reports", "card-report.json", HttpStatusCode.Created);
    }

    public void Answer(string route, string fixture, HttpStatusCode status = HttpStatusCode.OK) => this.answers[route] = (status, fixture);

    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var path = request.RequestUri!.AbsolutePath;
        path = path.StartsWith("/api/", StringComparison.Ordinal) ? path["/api".Length..] : path;
        var (status, fixture) = this.answers.TryGetValue($"{request.Method.Method} {path}", out var found)
            ? found
            : (HttpStatusCode.NotFound, "problem-not-found.json");
        var type = (int)status < 400 ? "application/json" : "application/problem+json";
        var body = File.ReadAllText(Path.Combine(this.fixtures, fixture));
        return Task.FromResult(new HttpResponseMessage(status) { Content = new StringContent(body, Encoding.UTF8, type) });
    }
}

/// <summary>
/// Card faces painted for the pictures, standing in for the site's scans: the card's name
/// along the bottom and a sun at the top, so a reversed card plainly stands on its head.
/// Each face becomes a sheet ImGui can draw, numbered after the font's, and lasts until the
/// scene is drawn.
/// </summary>
internal sealed unsafe class CardFaces : ICardTextures, IDisposable
{
    private const int Width = 300;
    private const int Height = 516;

    private readonly List<Canvas.Sheet> sheets;
    private readonly IReadOnlyDictionary<(int DeckId, int CardId), string> names;
    private readonly Dictionary<(int DeckId, int CardId), Face> painted = new();

    /// <param name="names">Each card's name by its deck and its id.</param>
    public CardFaces(List<Canvas.Sheet> sheets, IReadOnlyDictionary<(int DeckId, int CardId), string> names)
    {
        this.sheets = sheets;
        this.names = names;
    }

    public IDalamudTextureWrap? GetCard(int deckId, int cardId)
    {
        if (!this.painted.TryGetValue((deckId, cardId), out var face))
            this.painted[(deckId, cardId)] = face = this.Paint(deckId, cardId);
        return face;
    }

    public void Dispose()
    {
        foreach (var face in this.painted.Values)
            Marshal.FreeHGlobal(this.sheets[(int)(nint)face.Handle.Handle - 1].Pixels);
        this.painted.Clear();
    }

    private Face Paint(int deckId, int cardId)
    {
        using var bitmap = new Bitmap(Width, Height, PixelFormat.Format32bppArgb);
        using (var g = Graphics.FromImage(bitmap))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.TextRenderingHint = TextRenderingHint.AntiAliasGridFit;

            using var night = new LinearGradientBrush(new Rectangle(0, 0, Width, Height), Color.FromArgb(52, 40, 92), Color.FromArgb(16, 14, 32), 90f);
            g.FillRectangle(night, 0, 0, Width, Height);
            using var gold = new Pen(Color.FromArgb(214, 186, 120), 5);
            g.DrawRectangle(gold, 14, 14, Width - 28, Height - 28);

            using var sun = new SolidBrush(Color.FromArgb(236, 204, 128));
            g.FillEllipse(sun, (Width / 2) - 36, 64, 72, 72);
            using var rays = new Pen(Color.FromArgb(236, 204, 128), 4);
            for (var ray = 0; ray < 12; ray++)
            {
                var angle = ray * MathF.PI / 6;
                var (cos, sin) = (MathF.Cos(angle), MathF.Sin(angle));
                g.DrawLine(rays, (Width / 2) + (cos * 46), 100 + (sin * 46), (Width / 2) + (cos * 62), 100 + (sin * 62));
            }

            using var numberFont = new Font("Georgia", 64, FontStyle.Bold, GraphicsUnit.Pixel);
            using var nameFont = new Font("Georgia", 26, FontStyle.Regular, GraphicsUnit.Pixel);
            using var ink = new SolidBrush(Color.FromArgb(240, 228, 200));
            using var centred = new StringFormat { Alignment = StringAlignment.Center, LineAlignment = StringAlignment.Center };
            g.DrawString(cardId.ToString(System.Globalization.CultureInfo.InvariantCulture), numberFont, ink, new RectangleF(0, 200, Width, 120), centred);
            g.FillRectangle(night, 28, Height - 104, Width - 56, 72);
            g.DrawRectangle(gold, 28, Height - 104, Width - 56, 72);
            var name = this.names.TryGetValue((deckId, cardId), out var known) && known.Length > 0 ? known : $"Card {cardId}";
            g.DrawString(name, nameFont, ink, new RectangleF(28, Height - 104, Width - 56, 72), centred);
        }

        // Four bytes a pixel, red first, where Windows keeps them blue first.
        var bits = bitmap.LockBits(new Rectangle(0, 0, Width, Height), ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
        var pixels = (byte*)Marshal.AllocHGlobal(Width * Height * 4);
        for (var y = 0; y < Height; y++)
        {
            var row = (byte*)bits.Scan0 + (y * bits.Stride);
            for (var x = 0; x < Width; x++)
            {
                var from = row + (x * 4);
                var to = pixels + (((y * Width) + x) * 4);
                (to[0], to[1], to[2], to[3]) = (from[2], from[1], from[0], from[3]);
            }
        }

        bitmap.UnlockBits(bits);
        this.sheets.Add(new Canvas.Sheet((IntPtr)pixels, Width, Height));
        return new Face(new ImTextureID((nint)this.sheets.Count), Width, Height);
    }

    private sealed class Face(ImTextureID handle, int width, int height) : IDalamudTextureWrap
    {
        public ImTextureID Handle => handle;

        public int Width => width;

        public int Height => height;

        public void Dispose()
        {
        }
    }
}
