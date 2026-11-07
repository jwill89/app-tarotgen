<?php

namespace Tarot\Service;

use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;

/**
 * The social meta og.php gives /reading/{id}: crawlers can't run the SPA, so the
 * shell's generic title and og:/twitter: tags are swapped for the reading's.
 *
 * It says only what anyone with the link may see. A reading that isn't there
 * for an anonymous visitor — unknown, past its expiry, or private — gets the
 * generic meta, exactly alike, so a share preview can't tell them apart. A
 * password-protected one gives away its name, as its locked page does. The
 * owner-only journal fields (question, querent, tags) never appear.
 *
 * @phpstan-type ShareMeta array{title: string, description: string, image: string, url: string}
 */
final readonly class ReadingShareMeta
{
    private const string SITE_URL = 'https://tarotgen.io';
    private const string GENERIC_TITLE = 'TarotGen.io Tarot Generator';
    private const string GENERIC_DESCRIPTION = 'View this tarot reading on TarotGen.io.';
    private const string BANNER = '/assets/share_banner.png';

    /**
     * @param string $webRoot Where assets/ lives (og.php's own directory), for the share image.
     */
    public function __construct(
        private ReadingRepository $readings,
        private DeckRepository $decks,
        private string $webRoot,
    ) {}

    /**
     * The meta for reading $id, as an anonymous visitor may see it.
     *
     * @return ShareMeta
     */
    public function describe(string $id): array
    {
        $reading = $this->readings->get($id);
        if (!$reading instanceof Reading || !$reading->isVisibleTo(null)) {
            return self::generic($this->webRoot, $id);
        }

        if ($reading->password_protected) {
            // Don't leak details of a password-protected reading to crawlers.
            return self::meta(
                $this->webRoot,
                $id,
                ($reading->reading_name ?? '') !== '' ? (string) $reading->reading_name : 'Protected Tarot Reading',
                'This reading is password-protected on TarotGen.io.',
            );
        }

        $info = json_decode($reading->reading_info, true);
        $info = is_array($info) ? $info : [];
        $deckId = (int) ($info['deck_id'] ?? 0);
        $count = is_array($info['draw'] ?? null) ? count($info['draw']) : 0;
        $spread = is_array($info['spread'] ?? null) ? (string) ($info['spread']['name'] ?? '') : '';

        $deck = $deckId > 0 ? $this->decks->get($deckId) : null;
        $deckName = $deck instanceof Deck ? $deck->name : '';

        // A custom reading name wins; otherwise describe the spread / card count.
        $title = match (true) {
            ($reading->reading_name ?? '') !== '' => (string) $reading->reading_name,
            $spread !== '' => $spread . ' — Tarot Reading',
            $count > 0 => $count . '-Card Tarot Reading',
            default => 'Tarot Reading',
        };
        $description = ($deckName !== '' ? $deckName . ' · ' : '') . 'A tarot reading on TarotGen.io.';

        // Prefer the deck's card back as the share image, when it exists.
        $back = '/assets/decks/' . $deckId . '/Card_Back.png';
        $image = $deckId > 0 && is_file($this->webRoot . $back) ? $back : self::BANNER;

        return self::meta($this->webRoot, $id, $title, $description, $image);
    }

    /**
     * The meta for a reading there's nothing to say about: unknown, expired,
     * private, or the database couldn't be read.
     *
     * @return ShareMeta
     */
    public static function generic(string $webRoot, string $id): array
    {
        return self::meta($webRoot, $id, self::GENERIC_TITLE, self::GENERIC_DESCRIPTION);
    }

    /**
     * $template (the built app shell) with its generic title, description,
     * canonical link and social tags replaced by $meta, and robots told not to
     * index the page.
     *
     * @param ShareMeta $meta
     */
    public static function inject(string $template, array $meta): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // Strip the build's generic title + managed social tags, then inject our own.
        $template = preg_replace('#<title>.*?</title>#is', '', $template, 1) ?? $template;
        $template = preg_replace(
            '#[ \t]*<meta[^>]*(?:property="(?:og|twitter):[^"]*"|name="(?:description|title)")[^>]*>\s*#i',
            '',
            $template
        ) ?? $template;
        // Drop the static canonical so we can point it at the reading itself.
        $template = preg_replace('#[ \t]*<link[^>]*rel="canonical"[^>]*>\s*#i', '', $template) ?? $template;

        $html = '<title>' . $esc($meta['title']) . '</title>' . "\n"
            // Individual readings are shareable but shouldn't clutter search results.
            . '    <meta name="robots" content="noindex, follow" />' . "\n"
            . '    <link rel="canonical" href="' . $esc($meta['url']) . '" />' . "\n"
            . '    <meta name="title" content="' . $esc($meta['title']) . '" />' . "\n"
            . '    <meta name="description" content="' . $esc($meta['description']) . '" />' . "\n"
            . '    <meta property="og:type" content="article" />' . "\n"
            . '    <meta property="og:url" content="' . $esc($meta['url']) . '" />' . "\n"
            . '    <meta property="og:title" content="' . $esc($meta['title']) . '" />' . "\n"
            . '    <meta property="og:description" content="' . $esc($meta['description']) . '" />' . "\n"
            . '    <meta property="og:image" content="' . $esc($meta['image']) . '" />' . "\n"
            . '    <meta property="twitter:card" content="summary_large_image" />' . "\n"
            . '    <meta property="twitter:url" content="' . $esc($meta['url']) . '" />' . "\n"
            . '    <meta property="twitter:title" content="' . $esc($meta['title']) . '" />' . "\n"
            . '    <meta property="twitter:description" content="' . $esc($meta['description']) . '" />' . "\n"
            . '    <meta property="twitter:image" content="' . $esc($meta['image']) . '" />' . "\n";

        return str_replace('</head>', $html . '</head>', $template);
    }

    /**
     * @param string $image A path under the web root.
     * @return ShareMeta
     */
    private static function meta(string $webRoot, string $id, string $title, string $description, string $image = self::BANNER): array
    {
        // Cache-bust the share image with the file's mtime so social crawlers (which
        // cache OG images hard, keyed by URL) refetch whenever the image changes.
        $mtime = @filemtime($webRoot . $image);

        return [
            'title'       => $title,
            'description' => $description,
            'image'       => self::SITE_URL . $image . ($mtime !== false ? '?v=' . $mtime : ''),
            'url'         => self::SITE_URL . '/reading/' . $id,
        ];
    }
}
