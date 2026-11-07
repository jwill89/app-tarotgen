using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using Dalamud.Utility;

namespace TarotGen.Plugin.Services;

/// <summary>
/// The browser half of the plugin's two flows, the account link
/// (<see cref="LinkService"/>) and the draw key (<see cref="DrawKeyService"/>):
/// OAuth-style authorization code + PKCE over an RFC 8252 loopback redirect.
/// Opens a site page with a PKCE challenge and a loopback redirect, and waits on
/// a local listener for the browser to come back with a one-time code; the
/// caller swaps code and verifier with the API. Runs off the framework thread.
/// </summary>
internal static class LoopbackCodeFlow
{
    /// <summary>The one-time code the browser brought back, and the verifier it needs.</summary>
    internal sealed record Returned(string Code, string Verifier);

    /// <summary>
    /// Open <c>{SiteUrl}{pagePath}</c> with the PKCE request and wait for the
    /// browser's redirect. Null on a timeout or a cancel; throws when the browser
    /// came back without a code or with another flow's state.
    /// </summary>
    /// <param name="pagePath">The site page, e.g. <c>/authorize</c>.</param>
    /// <param name="extraQuery">Query parameters the page wants besides PKCE's, each ending in <c>&amp;</c>.</param>
    /// <param name="doneMessage">What the browser tab says once the code is in.</param>
    public static async Task<Returned?> RunAsync(
        string pagePath, string extraQuery, string doneMessage, TimeSpan timeout, CancellationToken ct)
    {
        var verifier = RandomUrlToken(32);
        var challenge = Base64Url(SHA256.HashData(Encoding.ASCII.GetBytes(verifier)));
        var state = RandomUrlToken(16);

        var port = FreeLoopbackPort();
        var redirectUri = $"http://127.0.0.1:{port}/callback";

        using var listener = new HttpListener();
        listener.Prefixes.Add($"http://127.0.0.1:{port}/");
        listener.Start();

        // Stopping the listener is what unblocks GetContextAsync on a cancel.
        using var stopOnCancel = ct.Register(() =>
        {
            try { listener.Stop(); }
            catch { /* already stopped */ }
        });

        Util.OpenLink(
            $"{Configuration.SiteUrl}{pagePath}?{extraQuery}"
            + $"redirect_uri={Uri.EscapeDataString(redirectUri)}"
            + $"&code_challenge={challenge}&code_challenge_method=S256"
            + $"&state={Uri.EscapeDataString(state)}");

        // Wait for the browser to redirect back — or a cancel / timeout.
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(ct);
        deadline.CancelAfter(timeout);

        var contextTask = listener.GetContextAsync();
        var finished = await Task.WhenAny(contextTask, Task.Delay(Timeout.Infinite, deadline.Token))
            .ConfigureAwait(false);
        if (finished != contextTask)
            return null;

        var context = await contextTask.ConfigureAwait(false);
        var query = context.Request.QueryString;
        var code = query["code"];

        if (query["state"] != state || string.IsNullOrEmpty(code))
        {
            RespondHtml(context, "TarotGen: cancelled. You can close this tab.");
            throw new InvalidOperationException("The browser didn't send this request's code back.");
        }

        RespondHtml(context, doneMessage);
        return new Returned(code, verifier);
    }

    private static int FreeLoopbackPort()
    {
        var probe = new TcpListener(IPAddress.Loopback, 0);
        probe.Start();
        var port = ((IPEndPoint)probe.LocalEndpoint).Port;
        probe.Stop();
        return port;
    }

    private static string RandomUrlToken(int bytes)
    {
        var buffer = new byte[bytes];
        RandomNumberGenerator.Fill(buffer);
        return Base64Url(buffer);
    }

    private static string Base64Url(byte[] data) =>
        Convert.ToBase64String(data).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static void RespondHtml(HttpListenerContext context, string message)
    {
        var html = Encoding.UTF8.GetBytes(
            "<!doctype html><html><head><meta charset='utf-8'><title>TarotGen</title></head>"
            + "<body style='font-family:sans-serif;text-align:center;padding-top:3rem'>"
            + $"<h2>{message}</h2></body></html>");
        context.Response.ContentType = "text/html; charset=utf-8";
        context.Response.ContentLength64 = html.Length;
        context.Response.OutputStream.Write(html, 0, html.Length);
        context.Response.Close();
    }
}
