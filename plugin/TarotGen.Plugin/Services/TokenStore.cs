using System.Security.Cryptography;
using System.Text;
using Dalamud.Plugin;
using Dalamud.Plugin.Services;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Holds the plugin's two credentials and persists them, each DPAPI-encrypted for
/// the current Windows user where available: the account Bearer token of a
/// linked account, and the draw key that lets an unlinked install draw. Under
/// Wine (or if DPAPI throws) it falls back to a plaintext (Base64) store — no
/// worse than Dalamud's plaintext config, and both are revocable server-side
/// regardless. Neither is ever shown in the UI.
/// </summary>
public sealed class TokenStore
{
    // A non-secret, plugin-scoped entropy so the blob is bound to this plugin.
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("TarotGen.Plugin.v1");

    private readonly Configuration config;
    private readonly IDalamudPluginInterface pluginInterface;
    private readonly IPluginLog log;

    private string? cachedToken;
    private string? cachedDrawKey;

    public TokenStore(Configuration config, IDalamudPluginInterface pluginInterface, IPluginLog log)
    {
        this.config = config;
        this.pluginInterface = pluginInterface;
        this.log = log;
        this.cachedToken = this.Decrypt(config.EncryptedToken, config.TokenIsEncrypted, "account token");
        this.cachedDrawKey = this.Decrypt(config.EncryptedDrawKey, config.DrawKeyIsEncrypted, "draw key");
    }

    /// <summary>An account token is present (unlocks finalize / favorites / My Readings).</summary>
    public bool IsLinked => !string.IsNullOrEmpty(this.cachedToken);

    public string? Token => this.cachedToken;

    public string LinkedName => this.config.LinkedName ?? string.Empty;

    /// <summary>A draw key is present: an unlinked install can draw.</summary>
    public bool HasDrawKey => !string.IsNullOrEmpty(this.cachedDrawKey);

    public string? DrawKey => this.cachedDrawKey;

    public void Save(string token, string displayName)
    {
        this.cachedToken = token;
        var (blob, encrypted) = this.Encrypt(token);
        this.config.EncryptedToken = blob;
        this.config.TokenIsEncrypted = encrypted;
        this.config.LinkedName = displayName;
        this.config.Save(this.pluginInterface);
    }

    public void Clear()
    {
        this.cachedToken = null;
        this.config.EncryptedToken = null;
        this.config.TokenIsEncrypted = false;
        this.config.LinkedName = null;

        this.config.Save(this.pluginInterface);
    }

    public void SaveDrawKey(string key)
    {
        this.cachedDrawKey = key;
        var (blob, encrypted) = this.Encrypt(key);
        this.config.EncryptedDrawKey = blob;
        this.config.DrawKeyIsEncrypted = encrypted;
        this.config.Save(this.pluginInterface);
    }

    public void ClearDrawKey()
    {
        this.cachedDrawKey = null;
        this.config.EncryptedDrawKey = null;
        this.config.DrawKeyIsEncrypted = false;

        this.config.Save(this.pluginInterface);
    }

    /// <summary>
    /// The site refused <paramref name="credential"/> as no longer valid: forget it,
    /// if it is still the one held (a new one may have arrived meanwhile).
    /// </summary>
    public void Forget(string credential)
    {
        if (credential == this.cachedToken)
            this.Clear();
        else if (credential == this.cachedDrawKey)
            this.ClearDrawKey();
    }

    private (string Blob, bool Encrypted) Encrypt(string secret)
    {
        var raw = Encoding.UTF8.GetBytes(secret);
        try
        {
            var protectedBytes = ProtectedData.Protect(raw, Entropy, DataProtectionScope.CurrentUser);
            return (Convert.ToBase64String(protectedBytes), true);
        }
        catch (Exception ex)
        {
            this.log.Warning($"DPAPI unavailable ({ex.Message}); storing it unencrypted.");
            return (Convert.ToBase64String(raw), false);
        }
    }

    private string? Decrypt(string? blob, bool encrypted, string what)
    {
        if (string.IsNullOrEmpty(blob))
            return null;

        try
        {
            var raw = Convert.FromBase64String(blob);
            if (!encrypted)
                return Encoding.UTF8.GetString(raw);

            var unprotected = ProtectedData.Unprotect(raw, Entropy, DataProtectionScope.CurrentUser);
            return Encoding.UTF8.GetString(unprotected);
        }
        catch (Exception ex)
        {
            this.log.Warning($"Could not decrypt the stored {what}: {ex.Message}");
            return null;
        }
    }
}
