<?php

namespace Tarot\Service;

use Generator;
use Tarot\Config\Env;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Structure\Reading;
use Tarot\Utility\Clock;

/**
 * A user's whole journal, every reading they own, as a download: JSON that keeps
 * everything (the spread snapshot with its positions, notes, flags), or CSV with
 * one row per drawn card for a spreadsheet.
 *
 * Both come out in pieces, a reading at a time, for the controller to write into
 * the response body (php://temp, which spills to disk past 2 MB). So a journal of
 * thousands of readings is never decoded all at once: what stays in memory is the
 * readings' stored rows, a few KB each.
 *
 * @phpstan-type JournalCard array{position: int, position_title: string|null, card_id: int, card_name: string, reversed: bool}
 * @phpstan-type JournalReading array{
 *   reading_id: string, link: string, reading_time: string, reading_name: string|null,
 *   reading_notes: string|null, question: string|null, querent: string|null, tags: list<string>,
 *   deck_id: int, deck_name: string, spread: array<mixed>|null,
 *   cards: list<JournalCard>, is_final: bool, visibility: string, password_protected: bool
 * }
 */
final readonly class JournalExport
{
    /** The CSV's columns, in order. */
    private const array CSV_HEADER = [
        'Reading ID', 'Reading Time (UTC)', 'Reading Name', 'Question', 'Querent', 'Tags', 'Privacy',
        'Deck', 'Spread', 'Position', 'Position Title', 'Card', 'Reversed', 'Notes',
    ];

    /** The CSV's Privacy column, by visibility. */
    private const array PRIVACY = ['link' => 'Anyone with the link', 'password' => 'Password', 'private' => 'Private'];

    /**
     * What a spreadsheet takes for the start of a formula when a cell begins with
     * it (OWASP "CSV injection"): a reading's name or notes could be written to
     * run something on the computer that opens the file.
     */
    private const string FORMULA_STARTS = "=+-@\t\r";

    public function __construct(
        private ReadingRepository $readings,
        private DeckRepository $decks,
    ) {}

    /**
     * The journal as one JSON document (JournalExport in the API spec), pretty-
     * printed: it's a file someone may well open and read.
     *
     * @return Generator<int,string>
     */
    public function json(int $userId): Generator
    {
        yield "{\n    \"exported_at\": " . json_encode(Clock::now(), JSON_THROW_ON_ERROR) . ",\n    \"readings\": [";

        $first = true;
        foreach ($this->entries($userId) as $entry) {
            $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            // Nested two levels deep. A JSON string never holds a raw line break,
            // so every line start is structure, safe to indent.
            yield ($first ? "\n" : ",\n") . (preg_replace('/^/m', '        ', $json) ?? $json);
            $first = false;
        }

        yield ($first ? '' : "\n    ") . "]\n}\n";
    }

    /**
     * The journal as CSV, one row per drawn card: a reading's name, question,
     * querent, tags (comma-separated) and notes repeat on each of its cards' rows.
     * A reading with no cards still gets one row, so its notes aren't lost.
     *
     * It starts with a UTF-8 byte-order mark. Excel, where most of these files
     * will be opened, reads a CSV without one in the PC's legacy code page, so an
     * accented card name ("L'Étoile"), a dash or notes in a non-Latin script would
     * come out garbled. Google Sheets, Numbers and LibreOffice skip the mark; a
     * script reads the file as utf-8-sig.
     *
     * @return Generator<int,string>
     */
    public function csv(int $userId): Generator
    {
        yield "\u{FEFF}" . self::csvLine(self::CSV_HEADER);

        foreach ($this->entries($userId) as $entry) {
            $spreadName = $entry['spread'] === null ? 'Free Draw' : (string) ($entry['spread']['name'] ?? '');
            $cards = $entry['cards'] === [] ? [null] : $entry['cards'];

            foreach ($cards as $card) {
                yield self::csvLine([
                    $entry['reading_id'],
                    $entry['reading_time'],
                    $entry['reading_name'] ?? '',
                    $entry['question'] ?? '',
                    $entry['querent'] ?? '',
                    implode(', ', $entry['tags']),
                    self::PRIVACY[$entry['visibility']] ?? $entry['visibility'],
                    $entry['deck_name'],
                    $spreadName,
                    $card === null ? '' : (string) $card['position'],
                    $card['position_title'] ?? '',
                    $card['card_name'] ?? '',
                    $card === null ? '' : ($card['reversed'] ? 'Yes' : 'No'),
                    $entry['reading_notes'] ?? '',
                ]);
            }
        }
    }

    /**
     * One CSV cell: quoted (RFC 4180, so commas, quotes and line breaks in notes
     * survive) and, when it starts like a formula, prefixed with an apostrophe,
     * which spreadsheets show as text and don't evaluate.
     */
    public static function csvCell(string $value): string
    {
        if ($value !== '' && str_contains(self::FORMULA_STARTS, $value[0])) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * @param list<string> $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map(self::csvCell(...), $cells)) . "\r\n";
    }

    /**
     * The user's readings, newest first (as My Readings lists them), each as the
     * export describes it.
     *
     * @return Generator<int,JournalReading>
     */
    private function entries(int $userId): Generator
    {
        // Only an approved deck's name: the reading page names no other (a deck
        // awaiting approval is for admins' eyes), so the export doesn't either.
        $deckNames = [];
        foreach ($this->decks->getApproved() as $deck) {
            $deckNames[$deck->deck_id] = $deck->name;
        }

        $base = rtrim(Env::get('APP_URL', 'https://tarotgen.io'), '/');

        foreach ($this->readings->listByUser($userId) as $reading) {
            yield $this->entry($reading, $deckNames, $base);
        }
    }

    /**
     * @param array<int,string> $deckNames
     * @return JournalReading
     */
    private function entry(Reading $reading, array $deckNames, string $base): array
    {
        // One damaged row shouldn't sink the whole export: it comes out empty.
        $info = json_decode($reading->reading_info, true);
        if (!is_array($info)) {
            $info = [];
        }

        $deckId = (int) ($info['deck_id'] ?? 0);
        $spread = is_array($info['spread'] ?? null) ? $info['spread'] : null;

        // A spread position holds the card drawn in its `order` (1-based); a card
        // drawn after the layout was placed has none yet.
        $titles = [];
        foreach (is_array($spread['positions'] ?? null) ? $spread['positions'] : [] as $position) {
            if (is_array($position)) {
                $titles[(int) ($position['order'] ?? 0)] = (string) ($position['title'] ?? '');
            }
        }

        $cards = [];
        foreach (is_array($info['draw'] ?? null) ? array_values($info['draw']) : [] as $i => $card) {
            if (!is_array($card)) {
                continue;
            }
            $cardId = (int) ($card['card_id'] ?? 0);
            $name = $card['card_name'] ?? null;
            $cards[] = [
                'position'       => $i + 1,
                'position_title' => $spread === null ? null : ($titles[$i + 1] ?? null),
                'card_id'        => $cardId,
                // As the reading page names it: an extra card nobody has named yet
                // is stored without a name.
                'card_name'      => is_string($name) && $name !== '' ? $name : "Card #{$cardId}",
                'reversed'       => (bool) ($card['reversed'] ?? false),
            ];
        }

        return [
            'reading_id'         => $reading->reading_id,
            'link'               => $base . '/reading/' . rawurlencode($reading->reading_id),
            'reading_time'       => $reading->reading_time,
            'reading_name'       => $reading->reading_name,
            'reading_notes'      => $reading->reading_notes,
            'question'           => $reading->question,
            'querent'            => $reading->querent,
            'tags'               => $reading->tags,
            'deck_id'            => $deckId,
            'deck_name'          => $deckNames[$deckId] ?? "Deck #{$deckId}",
            'spread'             => $spread,
            'cards'              => $cards,
            'is_final'           => $reading->is_final,
            'visibility'         => $reading->visibility,
            'password_protected' => $reading->password_protected,
        ];
    }
}
