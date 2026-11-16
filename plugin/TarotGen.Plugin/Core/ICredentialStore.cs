namespace TarotGen.Plugin.Core;

/// <summary>
/// Where the plugin keeps its two credentials: a linked account's Bearer token, and
/// the draw key that lets an unlinked install draw. Neither is ever shown in the UI.
/// </summary>
public interface ICredentialStore
{
    /// <summary>The linked account's token, or null when no account is linked.</summary>
    string? Token { get; }

    /// <summary>The draw key, or null when this install has none.</summary>
    string? DrawKey { get; }

    /// <summary>The linked account's display name; empty when none is linked.</summary>
    string LinkedName { get; }

    void Save(string token, string displayName);

    void Clear();

    void SaveDrawKey(string key);

    void ClearDrawKey();
}
