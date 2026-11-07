using Dalamud.Configuration;
using Dalamud.Plugin;

namespace TarotGen.Plugin;

/// <summary>
/// Persisted plugin settings. Stored by Dalamud as plaintext JSON under
/// %AppData%\XIVLauncher\pluginConfigs\TarotGen.Plugin.json, which is why the
/// account token is DPAPI-encrypted (TokenStore) before it lands here. The link
/// flow is described in plugin/README.md, "How it authenticates".
/// </summary>
[Serializable]
public sealed class Configuration : IPluginConfiguration
{
    /// <summary>
    /// The TarotGen site origin — a constant (the plugin only ever talks to
    /// tarotgen.io). REST calls go to <c>{SiteUrl}/api</c>, card art to
    /// <c>{SiteUrl}/assets</c>.
    /// </summary>
    public const string SiteUrl = "https://tarotgen.io";

    public int Version { get; set; } = 1;

    // Defaults the New Reading form starts from (also editable in Settings).
    public int? DefaultDeckId { get; set; }
    public int? DefaultSpreadId { get; set; }
    public bool UseReversals { get; set; } = true;
    public bool UseAdditionalCards { get; set; }
    public int FreeDrawCount { get; set; } = 3;

    // Account link. The token is DPAPI-encrypted at rest where available;
    // TokenIsEncrypted records which path was used so it decrypts correctly.
    public string? EncryptedToken { get; set; }
    public bool TokenIsEncrypted { get; set; }
    public string? LinkedName { get; set; }

    // Draw key (an unlinked install's credential for drawing), stored the same way
    // and apart from the account token: linking or unlinking leaves it be.
    public string? EncryptedDrawKey { get; set; }
    public bool DrawKeyIsEncrypted { get; set; }

    // First-run onboarding — shown once, then never again.
    public bool OnboardingComplete { get; set; }

    // The notice that drawing now needs a draw key, for an install set up before
    // 0.8.0 (onboarded, with no such flag in its saved config). New installs learn
    // it in onboarding, so finishing onboarding sets it too.
    public bool DrawKeyNoticeSeen { get; set; }

    public void Save(IDalamudPluginInterface pluginInterface)
        => pluginInterface.SavePluginConfig(this);
}
