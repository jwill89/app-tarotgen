<?php

/**
 * Icon generator. Produces the PWA icons in frontend/public/ from two masters
 * in backend/assets/ using GD:
 *   - favicon.png       512x512 charcoal medallion  (tab / any-purpose mark)
 *   - favicon-mark.png  512x512 bare gold star-in-ring on transparency
 *
 * favicon.svg is the vector master; the two PNGs are its 512px rasters. Run:
 *   php scripts/gen_icons.php
 */

$tile = imagecreatefrompng(__DIR__ . '/../assets/favicon.png');
$mark = imagecreatefrompng(__DIR__ . '/../assets/favicon-mark.png');
if ($tile === false || $mark === false) {
    fwrite(STDERR, "Could not load assets/favicon.png and assets/favicon-mark.png\n");
    exit(1);
}
foreach ([$tile, $mark] as $im) {
    imagealphablending($im, true);
    imagesavealpha($im, true);
}

// The PWA icons live in the frontend public/ dir (Vite copies it to the site root).
$outDir = __DIR__ . '/../../frontend/public';

/** Resize a source onto a transparent square of $size and save. */
function resizeTransparent($src, int $size, string $path): void
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    imagepng($dst, $path);
    echo "wrote $path\n";
}

/**
 * Composite a source (scaled into the central safe zone) onto a vertical
 * charcoal gradient background. Used for the maskable + apple-touch icons, which
 * must be full-bleed (no transparency) so the OS mask has a background to crop.
 */
function composite($src, int $size, float $inner, string $path): void
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, true);
    imagesavealpha($dst, true);

    // Vertical charcoal gradient from #14141c (top) to #0a0a0e (bottom) — matches
    // the favicon medallion's own tile gradient for a cohesive family.
    [$r1, $g1, $b1] = [0x14, 0x14, 0x1c];
    [$r2, $g2, $b2] = [0x0a, 0x0a, 0x0e];
    for ($y = 0; $y < $size; $y++) {
        $t = $y / ($size - 1);
        $c = imagecolorallocate(
            $dst,
            (int) round($r1 + ($r2 - $r1) * $t),
            (int) round($g1 + ($g2 - $g1) * $t),
            (int) round($b1 + ($b2 - $b1) * $t)
        );
        imageline($dst, 0, $y, $size, $y, $c);
    }

    // Scale the mark into the central safe zone and centre it.
    $target = (int) round($size * $inner);
    $offset = (int) round(($size - $target) / 2);
    imagecopyresampled($dst, $src, $offset, $offset, 0, 0, $target, $target, imagesx($src), imagesy($src));

    imagepng($dst, $path);
    echo "wrote $path\n";
}

// Any-purpose: the medallion tile, downscaled on transparency.
resizeTransparent($tile, 512, $outDir . '/icon-512.png');
resizeTransparent($tile, 192, $outDir . '/icon-192.png');
// Maskable + apple-touch: the BARE mark on a full-bleed charcoal gradient, so the
// circular / rounded OS mask crops cleanly (no rounded-rect-in-a-circle artifact).
composite($mark, 512, 0.92, $outDir . '/icon-maskable-512.png');
composite($mark, 180, 0.86, $outDir . '/apple-touch-icon.png');
echo "done\n";
