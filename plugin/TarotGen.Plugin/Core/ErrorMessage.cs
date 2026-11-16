using System.Text.Json;

namespace TarotGen.Plugin.Core;

/// <summary>
/// The message a failed API call shows the player, read from the error body. The API
/// answers with RFC 9457 problem details (<c>application/problem+json</c>): <c>detail</c>
/// says why, and a failed validation lists each broken field in <c>errors</c>, each with a
/// <c>message</c>. An older server sent <c>{ "error": "…" }</c> or <c>{ "errors": ["…"] }</c>,
/// which are still read.
/// </summary>
public static class ErrorMessage
{
    /// <summary>
    /// <c>detail</c>; else the <c>errors[].message</c> joined with "; "; else the older
    /// <c>error</c> string or <c>errors</c> strings; else <c>HTTP {status}</c>.
    /// </summary>
    public static string From(int status, string? body) => Read(body) ?? $"HTTP {status}";

    private static string? Read(string? body)
    {
        if (string.IsNullOrWhiteSpace(body))
            return null;

        JsonDocument document;
        try
        {
            document = JsonDocument.Parse(body);
        }
        catch (JsonException)
        {
            // An HTML error page from a proxy, say: nothing in it for the player.
            return null;
        }

        using (document)
        {
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
                return null;

            return Text(root, "detail")
                ?? Joined(root, entry => entry.ValueKind == JsonValueKind.Object ? Text(entry, "message") : null)
                ?? Text(root, "error")
                ?? Joined(root, entry => entry.ValueKind == JsonValueKind.String ? entry.GetString() : null);
        }
    }

    private static string? Text(JsonElement element, string name)
        => element.TryGetProperty(name, out var value)
            && value.ValueKind == JsonValueKind.String
            && value.GetString() is { } text
            && !string.IsNullOrWhiteSpace(text)
                ? text
                : null;

    private static string? Joined(JsonElement root, Func<JsonElement, string?> message)
    {
        if (!root.TryGetProperty("errors", out var errors) || errors.ValueKind != JsonValueKind.Array)
            return null;

        var messages = errors.EnumerateArray()
            .Select(message)
            .Where(text => !string.IsNullOrWhiteSpace(text))
            .ToList();
        return messages.Count > 0 ? string.Join("; ", messages) : null;
    }
}
