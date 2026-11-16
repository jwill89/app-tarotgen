using System.Drawing;
using System.Drawing.Imaging;
using System.Numerics;
using System.Runtime.InteropServices;
using Dalamud.Bindings.ImGui;

namespace TarotGen.Plugin.Preview;

/// <summary>
/// Paints a frame of ImGui's triangles into a picture, in software. There is no graphics
/// device here and a still does not need one: ImGui hands over coloured, textured triangles,
/// its own smoothing included, and this fills them.
/// </summary>
internal static unsafe class Canvas
{
    /// <summary>A texture ImGui draws from, a sheet of the font atlas or a picture: four bytes a
    /// pixel, red first.</summary>
    public sealed record Sheet(IntPtr Pixels, int Width, int Height);

    public static void Save(ImDrawDataPtr frame, int width, int height, IReadOnlyList<Sheet> sheets, string file)
    {
        // The game behind the window, near enough: a dim room.
        var canvas = new Vector3[width * height];
        Array.Fill(canvas, new Vector3(0.16f, 0.17f, 0.21f));

        for (var n = 0; n < frame.CmdListsCount; n++)
        {
            var list = frame.Handle->CmdLists[n];
            for (var c = 0; c < list->CmdBuffer.Size; c++)
            {
                var command = list->CmdBuffer.Data[c];

                // A sheet's id is its place in the list plus one, as Program numbers them.
                var sheet = sheets[(int)(nint)command.TextureId.Handle - 1];
                var index = list->IdxBuffer.Data + command.IdxOffset;
                var vertex = list->VtxBuffer.Data + command.VtxOffset;
                for (var i = 0; i < command.ElemCount; i += 3)
                    Fill(canvas, width, height, command.ClipRect, vertex[index[i]], vertex[index[i + 1]], vertex[index[i + 2]], sheet);
            }
        }

        var bytes = new byte[width * height * 4];
        for (var i = 0; i < canvas.Length; i++)
        {
            var pixel = Vector3.Clamp(canvas[i], Vector3.Zero, Vector3.One) * 255;
            bytes[i * 4] = (byte)pixel.Z;
            bytes[(i * 4) + 1] = (byte)pixel.Y;
            bytes[(i * 4) + 2] = (byte)pixel.X;
            bytes[(i * 4) + 3] = 255;
        }

        using var bitmap = new Bitmap(width, height, PixelFormat.Format32bppArgb);
        var bits = bitmap.LockBits(new Rectangle(0, 0, width, height), ImageLockMode.WriteOnly, PixelFormat.Format32bppArgb);
        Marshal.Copy(bytes, 0, bits.Scan0, bytes.Length);
        bitmap.UnlockBits(bits);
        bitmap.Save(file, ImageFormat.Png);
    }

    private static void Fill(Vector3[] canvas, int width, int height, Vector4 clip, ImDrawVert a, ImDrawVert b, ImDrawVert c, Sheet sheet)
    {
        var area = Edge(a.Pos, b.Pos, c.Pos);
        if (area == 0)
            return;
        var left = Math.Max((int)MathF.Floor(Math.Max(clip.X, Math.Min(a.Pos.X, Math.Min(b.Pos.X, c.Pos.X)))), 0);
        var right = Math.Min((int)MathF.Ceiling(Math.Min(clip.Z, Math.Max(a.Pos.X, Math.Max(b.Pos.X, c.Pos.X)))), width);
        var top = Math.Max((int)MathF.Floor(Math.Max(clip.Y, Math.Min(a.Pos.Y, Math.Min(b.Pos.Y, c.Pos.Y)))), 0);
        var bottom = Math.Min((int)MathF.Ceiling(Math.Min(clip.W, Math.Max(a.Pos.Y, Math.Max(b.Pos.Y, c.Pos.Y)))), height);
        var (colorA, colorB, colorC) = (Color(a.Col), Color(b.Col), Color(c.Col));
        for (var y = top; y < bottom; y++)
        {
            for (var x = left; x < right; x++)
            {
                // A hair off the pixel's centre, so that no sample sits exactly on an edge two
                // triangles share and is painted by both.
                var at = new Vector2(x + 0.5013f, y + 0.5007f);
                if (at.X < clip.X || at.X >= clip.Z || at.Y < clip.Y || at.Y >= clip.W)
                    continue;
                var (wa, wb, wc) = (Edge(b.Pos, c.Pos, at) / area, Edge(c.Pos, a.Pos, at) / area, Edge(a.Pos, b.Pos, at) / area);
                if (wa < 0 || wb < 0 || wc < 0)
                    continue;
                var paint = ((colorA * wa) + (colorB * wb) + (colorC * wc)) * Sample(sheet, (a.Uv * wa) + (b.Uv * wb) + (c.Uv * wc));
                ref var pixel = ref canvas[(y * width) + x];
                pixel = Vector3.Lerp(pixel, new Vector3(paint.X, paint.Y, paint.Z), paint.W);
            }
        }
    }

    // Twice the triangle's area, signed by which way round its corners run.
    private static float Edge(Vector2 a, Vector2 b, Vector2 p) => ((b.X - a.X) * (p.Y - a.Y)) - ((b.Y - a.Y) * (p.X - a.X));

    private static Vector4 Color(uint packed) =>
        new Vector4(packed & 0xFF, (packed >> 8) & 0xFF, (packed >> 16) & 0xFF, packed >> 24) / 255f;

    // Between the four nearest pixels, as a graphics card would: ImGui draws its smoothed
    // lines by stretching a few pixels of the atlas.
    private static Vector4 Sample(Sheet sheet, Vector2 uv)
    {
        var x = (uv.X * sheet.Width) - 0.5f;
        var y = (uv.Y * sheet.Height) - 0.5f;
        var (x0, y0) = ((int)MathF.Floor(x), (int)MathF.Floor(y));
        var top = Vector4.Lerp(At(sheet, x0, y0), At(sheet, x0 + 1, y0), x - x0);
        var bottom = Vector4.Lerp(At(sheet, x0, y0 + 1), At(sheet, x0 + 1, y0 + 1), x - x0);
        return Vector4.Lerp(top, bottom, y - y0);
    }

    private static Vector4 At(Sheet sheet, int x, int y)
    {
        var pixel = (byte*)sheet.Pixels + (((Math.Clamp(y, 0, sheet.Height - 1) * sheet.Width) + Math.Clamp(x, 0, sheet.Width - 1)) * 4);
        return new Vector4(pixel[0], pixel[1], pixel[2], pixel[3]) / 255f;
    }
}
