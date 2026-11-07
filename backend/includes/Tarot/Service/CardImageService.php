<?php

namespace Tarot\Service;

use GdImage;

/**
 * Derives the web images the site serves from each deck's master scans.
 *
 * Masters (never modified):  assets/decks/<deck_id>/Card_XXXX.png
 * Display copy (lightbox):   assets/decks/<deck_id>/Card_XXXX.webp       full size, WebP q82
 * Thumbnail (grids, pickers): assets/decks/<deck_id>/thumbs/Card_XXXX.webp  400 px wide, WebP q80
 *
 * A master scan is ~2-5 MB of PNG; its full-size WebP is ~7-8% of that with no
 * visible difference at 1:1 (AUDIT.md §3.2), so the lightbox no longer pulls
 * megabytes per card. The PNGs stay the source of truth (and the plugin's).
 *
 * Generation is incremental: a card is (re)processed when either derived file
 * is missing or older than its PNG — so replacing a scan after a card report
 * refreshes its images on the next run. Writes go to a temp file renamed into
 * place, so an interrupted run never leaves a truncated image that later runs
 * would mistake for done.
 */
class CardImageService
{
    private const int THUMB_WIDTH = 400;
    private const int THUMB_QUALITY = 80;
    private const int DISPLAY_QUALITY = 82;

    private readonly string $decksRoot;

    /**
     * @param string|null $decksRoot Override the deck-images root (for tests).
     *                               Defaults to the web root's assets/decks.
     */
    public function __construct(?string $decksRoot = null)
    {
        // includes/Tarot/Service -> project root -> assets/decks
        $this->decksRoot = $decksRoot ?? (dirname(__DIR__, 3) . '/assets/decks');
    }

    /** Create the deck's image folder (and thumbs/) if they don't exist yet. */
    public function ensureDeckFolders(int $deckId): void
    {
        $dir = $this->deckDir($deckId);
        $thumbs = $dir . '/thumbs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        if (!is_dir($thumbs)) {
            @mkdir($thumbs, 0o775, true);
        }
    }

    /**
     * Generate missing or stale web images for one deck. Counts are per card:
     * `generated` = (re)processed, `skipped` = already current, `failed` = the
     * PNG couldn't be decoded or a derived file couldn't be written.
     *
     * @return array{deck_id:int,generated:int,skipped:int,failed:int}
     */
    public function generateForDeck(int $deckId): array
    {
        $dir = $this->deckDir($deckId);
        $counts = ['deck_id' => $deckId, 'generated' => 0, 'skipped' => 0, 'failed' => 0];

        if (!is_dir($dir)) {
            return $counts;
        }

        $thumbDir = $dir . '/thumbs';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0o775, true);
        }

        $pngs = glob($dir . '/Card_*.png');
        foreach ($pngs === false ? [] : $pngs as $png) {
            $name = basename($png, '.png');
            $display = $dir . '/' . $name . '.webp';
            $thumb = $thumbDir . '/' . $name . '.webp';

            if ($this->isCurrent($display, $png) && $this->isCurrent($thumb, $png)) {
                $counts['skipped']++;
                continue;
            }

            if ($this->process($png, $display, $thumb)) {
                $counts['generated']++;
            } else {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Generate missing or stale web images across many decks.
     *
     * @param  int[] $deckIds
     * @return array{decks:int,generated:int,skipped:int,failed:int}
     */
    public function generateAll(array $deckIds): array
    {
        $totals = ['decks' => count($deckIds), 'generated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($deckIds as $deckId) {
            $result = $this->generateForDeck($deckId);
            $totals['generated'] += $result['generated'];
            $totals['skipped'] += $result['skipped'];
            $totals['failed'] += $result['failed'];
        }

        return $totals;
    }

    /** Whether the card's master scan is on disk. */
    public function hasScan(int $deckId, int $cardId): bool
    {
        return is_file($this->scanPath($deckId, $cardId));
    }

    /**
     * Rebuild one card's web images from its master scan, whether or not they
     * look current: an SFTP client may keep a replaced scan's original
     * modification time, which would leave it "older" than the stale WebP that
     * generateForDeck() then skips. False when there is no scan, or it couldn't
     * be decoded or a derived file couldn't be written.
     */
    public function regenerateCard(int $deckId, int $cardId): bool
    {
        $png = $this->scanPath($deckId, $cardId);
        if (!is_file($png)) {
            return false;
        }

        $dir = $this->deckDir($deckId);
        $name = basename($png, '.png');
        $thumbDir = $dir . '/thumbs';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0o775, true);
        }

        return $this->process($png, "{$dir}/{$name}.webp", "{$thumbDir}/{$name}.webp");
    }

    /** A card's master scan file name, as the frontend's cardImageUrls() names it. */
    public static function scanFileName(int $cardId): string
    {
        return sprintf('Card_%04d.png', $cardId);
    }

    private function deckDir(int $deckId): string
    {
        return $this->decksRoot . '/' . $deckId;
    }

    private function scanPath(int $deckId, int $cardId): string
    {
        return $this->deckDir($deckId) . '/' . self::scanFileName($cardId);
    }

    /** A derived file is current when it exists and is at least as new as its PNG. */
    private function isCurrent(string $derived, string $png): bool
    {
        return is_file($derived) && filemtime($derived) >= filemtime($png);
    }

    /** Decode the PNG once and write both derived images. */
    private function process(string $png, string $display, string $thumb): bool
    {
        $src = @imagecreatefrompng($png);
        if ($src === false) {
            return false;
        }

        // Keep transparency (some card backs have alpha) in the display copy.
        imagesavealpha($src, true);

        // GdImage instances are freed automatically by the garbage collector
        // since PHP 8.0; imagedestroy() was deprecated in 8.5.
        return $this->writeWebp($src, $display, self::DISPLAY_QUALITY)
            && $this->writeWebp($this->scaleToWidth($src, self::THUMB_WIDTH), $thumb, self::THUMB_QUALITY);
    }

    /**
     * Scale to $width (never upscaling), preserving aspect ratio and alpha.
     *
     * @param positive-int $width
     */
    private function scaleToWidth(GdImage $src, int $width): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $tw = min($width, $w);
        $th = max(1, (int) round($h * ($tw / $w)));

        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        return $dst;
    }

    /** Write to a temp file and rename it into place (atomic on one filesystem). */
    private function writeWebp(GdImage $image, string $target, int $quality): bool
    {
        $tmp = $target . '.tmp-' . getmypid();

        if (!@imagewebp($image, $tmp, $quality)) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
