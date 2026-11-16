using System.Net;
using System.Numerics;
using System.Text.Json;
using TarotGen.Plugin.Core;
using TarotGen.Plugin.Windows;

namespace TarotGen.Plugin.Preview;

/// <summary>One state of the window worth looking at, at one size. <see cref="Then"/> runs once
/// the first frames have settled, for what the player would do next.</summary>
internal sealed record Scene(string Name, Vector2 Size, Action<Stage> Arrange, Action<Stage>? Then = null);

/// <summary>
/// Every state the window can be in, each drawn talking to the fixtures' site. A new tab, a
/// new pop-up, or a new way for one to stand gets a scene here.
/// </summary>
internal static class Scenes
{
    // The size the window opens at the first time, and its narrowest.
    private static readonly Vector2 Opening = new(680, 780);
    private static readonly Vector2 Narrow = new(460, 780);

    // The readings the site answers with, besides My Readings.
    private static readonly string[] Readings = ["reading-spread.json", "reading-celtic-cross.json", "reading-free.json"];

    // The Three of Cups, reversed, as the spread reading has it in its second place.
    private static readonly DrawCard ThreeOfCups = new() { CardId = 39, Reversed = true, CardName = "Three of Cups" };

    /// <summary>The name of each card the fixtures' readings drew, by deck and card.</summary>
    public static IReadOnlyDictionary<(int DeckId, int CardId), string> CardNames(string fixtures)
    {
        T Read<T>(string file) => JsonSerializer.Deserialize<T>(File.ReadAllText(Path.Combine(fixtures, file)), TarotApiClient.Json)!;

        var readings = Read<List<Reading>>("account-readings.json")
            .Concat(Readings.Select(Read<Reading>));
        var names = new Dictionary<(int, int), string>();
        foreach (var info in readings.Select(r => r.ReadingInfo).OfType<ReadingInfo>())
        {
            foreach (var card in info.Draw)
                names[(info.DeckId, card.CardId)] = card.CardName;
        }

        return names;
    }

    public static IEnumerable<Scene> All()
    {
        // A player with a draw key, and one with a linked account.
        static void Keyed(Stage stage) => stage.Credentials.SaveDrawKey("tg_dk_8e2b7c41a9d05f36e1c4b9a72d0f5e18");
        static void Linked(Stage stage) => stage.Credentials.Save("tg_pat_5d1f0c3a9b7e42c68f2a1e0d9c4b7a63", "Mira Ashgrove");

        static Scene Reading(string name, Vector2 size, string code, Action<Stage>? who = null, Action<Stage>? then = null) =>
            new(name, size, stage =>
            {
                (who ?? Keyed)(stage);
                stage.Main.ShowReading(code);
            }, then);

        static Scene NewReading(string name, Vector2 size, Action<Stage> arrange, Action<Stage>? then = null) =>
            new(name, size, stage =>
            {
                arrange(stage);
                stage.Main.Show(MainWindow.Tab.NewReading);
            }, then);

        yield return new("reading-empty", Opening, stage =>
        {
            Keyed(stage);
            stage.Main.Show(MainWindow.Tab.Reading);
        });
        yield return Reading("reading-spread", Opening, "4f9c2a7b1e");
        yield return Reading("reading-spread-narrow", Narrow, "4f9c2a7b1e");
        yield return Reading("reading-celtic-cross", Opening, "b7e01d93c4", Linked);
        yield return Reading("reading-free-draw", Opening, "0c55e8a2d6");
        yield return Reading("reading-locked", Opening, "5e3a0d7c92");
        yield return Reading("reading-not-found", Opening, "deadbeef01");

        // The lightbox over the spread reading, with its report form, and the site turning a
        // report down.
        yield return Reading("lightbox", Opening, "4f9c2a7b1e", then: stage => stage.Panel.OpenLightbox(1, ThreeOfCups, "Present"));
        yield return Reading("lightbox-report", Opening, "4f9c2a7b1e", then: stage =>
        {
            stage.Panel.OpenLightbox(1, ThreeOfCups, "Present");
            stage.Panel.FillReport(0, "A dust speck near the top-left corner");
        });
        yield return Reading("lightbox-report-refused", Opening, "4f9c2a7b1e",
            who: stage => stage.Site.Answer("POST /card-reports", "problem-report-invalid.json", HttpStatusCode.UnprocessableContent),
            then: stage =>
            {
                stage.Panel.OpenLightbox(1, ThreeOfCups, "Present");
                stage.Panel.FillReport(4, string.Empty);
                stage.Panel.SendReport();
            });

        yield return NewReading("new-reading-spread", Opening, stage =>
        {
            Keyed(stage);
            (stage.Config.DefaultDeckId, stage.Config.DefaultSpreadId) = (1, 1);
        });
        yield return NewReading("new-reading-spread-narrow", Narrow, stage =>
        {
            Keyed(stage);
            (stage.Config.DefaultDeckId, stage.Config.DefaultSpreadId) = (1, 1);
        });
        // The deck with two extra cards, so its checkbox shows.
        yield return NewReading("new-reading-free-draw", Opening, stage =>
        {
            Keyed(stage);
            stage.Config.DefaultDeckId = 4;
        });
        yield return NewReading("new-reading-linked", Opening, stage =>
        {
            Linked(stage);
            stage.Config.DefaultSpreadId = 6;
        });
        yield return NewReading("new-reading-no-key", Opening, _ => { });
        // The site no longer takes the draw key: it is forgotten, and the tab says why.
        yield return NewReading("new-reading-key-revoked", Opening, stage =>
        {
            stage.Credentials.SaveDrawKey("tg_dk_00112233445566778899aabbccddeeff");
            stage.Site.Answer("POST /readings/generate", "problem-draw-key-revoked.json", HttpStatusCode.Unauthorized);
        }, then: stage => stage.Main.StartGenerate());
        yield return NewReading("new-reading-site-down", Opening, stage =>
        {
            Keyed(stage);
            stage.Site.Answer("GET /decks", "problem-unavailable.json", HttpStatusCode.ServiceUnavailable);
        });

        yield return new("my-readings", Opening, stage =>
        {
            Linked(stage);
            stage.Main.Show(MainWindow.Tab.MyReadings);
        });

        yield return new("settings-linked", Opening, stage =>
        {
            Linked(stage);
            (stage.Config.DefaultDeckId, stage.Config.DefaultSpreadId) = (1, 3);
            stage.Main.Show(MainWindow.Tab.Settings);
        });
        yield return new("settings-unlinked", Opening, stage =>
        {
            Keyed(stage);
            stage.Main.Show(MainWindow.Tab.Settings);
        });

        // The first-run pop-ups: a new install's welcome, and the notice an install from before
        // draw keys gets once.
        yield return new("onboarding", Opening, stage => stage.Config.OnboardingComplete = false);
        yield return new("draw-key-notice", Opening, stage => stage.Config.DrawKeyNoticeSeen = false);
    }
}
