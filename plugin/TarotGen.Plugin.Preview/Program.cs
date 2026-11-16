using System.Globalization;
using System.Numerics;
using System.Reflection;
using System.Runtime.CompilerServices;
using System.Runtime.InteropServices;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface.Style;
using Dalamud.Interface.Utility.Raii;
using TarotGen.Plugin.Core;
using TarotGen.Plugin.Windows;

namespace TarotGen.Plugin.Preview;

/// <summary>
/// Draws every scene of the plugin's window to a PNG: `preview [folder] [scale]`, where the
/// folder defaults to plugin/previews and the scale is Dalamud's global font scale (1.5 for
/// a player who has turned it up).
/// </summary>
internal static unsafe class Program
{
    // Dalamud's own default: twelve points.
    private const float FontSize = 16f;
    private const int Margin = 20;

    private static readonly string Roaming = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);

    // Where the build found Dalamud: DALAMUD_HOME, or the copy XIVLauncher keeps.
    private static readonly string DalamudHome = Environment.GetEnvironmentVariable("DALAMUD_HOME") is { Length: > 0 } home
        ? home
        : Path.Combine(Roaming, "XIVLauncher", "addon", "Hooks", "dev");

    private static int Main(string[] args)
    {
        if (args.Length > 2)
        {
            Console.Error.WriteLine("usage: preview [folder] [scale]");
            return 2;
        }

        // Dalamud's assemblies and ImGui itself are loaded from where Dalamud keeps them, so
        // the pictures are of the build the game would run. Nothing of Dalamud's is touched
        // before this, which is why the drawing is in a method of its own.
        AppDomain.CurrentDomain.AssemblyResolve += (_, wanted) =>
        {
            var file = Path.Combine(DalamudHome, new AssemblyName(wanted.Name).Name + ".dll");
            return File.Exists(file) ? Assembly.LoadFrom(file) : null;
        };
        Environment.SetEnvironmentVariable("PATH", DalamudHome + Path.PathSeparator + Environment.GetEnvironmentVariable("PATH"));

        var folder = args.Length > 0
            ? args[0]
            : typeof(Program).Assembly.GetCustomAttributes<AssemblyMetadataAttribute>().Single(a => a.Key == "Pictures").Value!;
        var scale = args.Length == 2 ? float.Parse(args[1], CultureInfo.InvariantCulture) : 1f;
        Draw(Directory.CreateDirectory(folder).FullName, scale);
        return 0;
    }

    [MethodImpl(MethodImplOptions.NoInlining)]
    private static void Draw(string folder, float scale)
    {
        // The glyphs each face is built with, kept for as long as the program: Latin, and the
        // dashes, the ellipsis and the middle dot the windows write; and the private use area,
        // where the icon face keeps its glyphs.
        var text = (ushort*)Marshal.AllocHGlobal(5 * sizeof(ushort));
        (text[0], text[1], text[2], text[3], text[4]) = (0x0020, 0x00FF, 0x2010, 0x2027, 0);
        var icons = (ushort*)Marshal.AllocHGlobal(3 * sizeof(ushort));
        (icons[0], icons[1], icons[2]) = (0xE000, 0xF8FF, 0);

        var fixtures = Path.Combine(AppContext.BaseDirectory, "Fixtures");
        var names = Scenes.CardNames(fixtures);
        foreach (var scene in Scenes.All())
        {
            // Each scene in an ImGui of its own, so none inherits another's open tab or pop-up:
            // the window is drawn as it first opens.
            var context = ImGui.CreateContext();
            var io = ImGui.GetIO();
            io.IniFilename = null;
            io.DeltaTime = 1f / 60;
            io.MousePos = new Vector2(float.MinValue);
            var sheets = LoadFont(io, text, icons);
            StyleModelV1.DalamudStandard.Apply();
            ImGui.GetStyle().ScaleAllSizes(scale);
            // The player's font scale: it sizes the text, and ImGuiHelpers.GlobalScale, which
            // the windows size themselves by, reads it.
            io.FontGlobalScale = scale;

            using var faces = new CardFaces(sheets, names);
            using var stage = new Stage(fixtures, faces);
            scene.Arrange(stage);
            stage.Main.OnOpen();

            var size = scene.Size * scale;
            io.DisplaySize = size + new Vector2(Margin * 2);

            // A layout settles over a few frames: a table measures on one and places on the
            // next. What the window asks of the site is answered between them.
            for (var frame = 0; frame < 8; frame++)
            {
                ImGui.NewFrame();
                ImGui.SetNextWindowPos(new Vector2(Margin));
                ImGui.SetNextWindowSize(size);
                var open = true;
                if (ImGui.Begin(stage.Main.WindowName, ref open, stage.Main.Flags))
                    stage.Main.Draw();
                ImGui.End();
                ImGui.Render();

                stage.Settle();
                if (frame == 1)
                {
                    scene.Then?.Invoke(stage);
                    stage.Settle();
                }
            }

            var file = Path.Combine(folder, scene.Name + ".png");
            Canvas.Save(ImGui.GetDrawData(), (int)io.DisplaySize.X, (int)io.DisplaySize.Y, sheets, file);
            Console.WriteLine(file);
            ImGui.DestroyContext(context);
        }
    }

    // Dalamud's default face, from the assets XIVLauncher downloads for it; ImGui's own
    // built-in one where those are not on the machine.
    private static List<Canvas.Sheet> LoadFont(ImGuiIOPtr io, ushort* text, ushort* icons)
    {
        var assets = Path.Combine(Roaming, "XIVLauncher", "dalamudAssets");
        var face = Directory.Exists(assets)
            ? Directory.EnumerateFiles(assets, "NotoSansCJKjp-Medium.otf", SearchOption.AllDirectories).OrderDescending().FirstOrDefault()
            : null;
        if (face is null)
        {
            io.Fonts.AddFontDefault();
        }
        else
        {
            io.Fonts.AddFontFromFileTTF(face, FontSize, null, text);
        }

        // Dalamud's icon face, the FontAwesome its icon names are numbered by, for the icons the
        // windows draw.
        var iconFile = Directory.Exists(assets)
            ? Directory.EnumerateFiles(assets, "FontAwesome710FreeSolid.otf", SearchOption.AllDirectories).OrderDescending().FirstOrDefault()
            : null;
        if (iconFile is not null)
        {
            var iconFace = io.Fonts.AddFontFromFileTTF(iconFile, FontSize, null, icons);
            Ui.IconFont = () => ImRaii.PushFont(iconFace);
        }

        io.Fonts.Build();

        var sheets = new List<Canvas.Sheet>();
        for (var i = 0; i < io.Fonts.Textures.Size; i++)
        {
            byte* pixels;
            int width, height;
            ImGui.GetTexDataAsRGBA32(io.Fonts, i, &pixels, &width, &height);
            sheets.Add(new Canvas.Sheet((IntPtr)pixels, width, height));
            io.Fonts.SetTexID(i, new ImTextureID((nint)(i + 1)));
        }

        return sheets;
    }
}

/// <summary>
/// One window as the plugin makes it, talking to a stand-in site that answers from the
/// fixtures: the main window, its reading panel, and what they hold.
/// </summary>
internal sealed class Stage : IDisposable
{
    public Stage(string fixtures, ICardTextures faces)
    {
        this.Site = new FixtureSite(fixtures);
        this.Api = new TarotApiClient(new HttpClient(this.Site), this.Credentials, Configuration.SiteUrl, _ => { });
        this.Link = new LinkService(this.Api, this.Credentials, _ => { }, _ => { });
        this.Keys = new DrawKeyService(this.Api, this.Credentials, _ => { }, _ => { });
        this.Panel = new ReadingPanel(this.Api, faces);
        this.Main = new MainWindow(this.Api, this.Panel, this.Link, this.Keys, this.Config, () => { });
    }

    public FixtureSite Site { get; }

    public Credentials Credentials { get; } = new();

    // A player who has seen the first-run pop-ups; a scene of one turns its own back on.
    public Configuration Config { get; } = new() { OnboardingComplete = true, DrawKeyNoticeSeen = true };

    public TarotApiClient Api { get; }

    public LinkService Link { get; }

    public DrawKeyService Keys { get; }

    public ReadingPanel Panel { get; }

    public MainWindow Main { get; }

    /// <summary>Waits for what the window asked of the site to come back.</summary>
    public void Settle()
    {
        var deadline = DateTime.UtcNow.AddSeconds(5);
        while (this.Main.Busy)
        {
            if (DateTime.UtcNow > deadline)
                throw new TimeoutException("The window is still waiting on the site.");
            Thread.Sleep(5);
        }
    }

    public void Dispose()
    {
        this.Link.Dispose();
        this.Keys.Dispose();
        this.Api.Dispose();
    }
}
