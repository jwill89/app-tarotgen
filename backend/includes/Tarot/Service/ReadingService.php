<?php

namespace Tarot\Service;

use JsonException;
use PDOException;
use Random\RandomException;
use Tarot\Exception\ApiException;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;
use Tarot\Structure\Spread;
use Tarot\Structure\UserSpread;
use Tarot\Utility\Input;

/**
 * The reading engine: turns a request (deck + options, or a hand-authored set
 * of cards) into a persisted {@see Reading}.
 *
 * This is pure domain logic with no knowledge of HTTP. Client-facing failures
 * are signalled by throwing {@see ApiException}; controllers translate those
 * into responses. Persistence and card-name lookups are injected so the engine
 * can be unit-tested in isolation.
 */
class ReadingService
{
    /**
     * Probability that any single packet is turned (180°) during the
     * cut-and-turn pass. In a single pass this is also the approximate
     * per-card reversal rate (0.27 ≈ 27%). A model parameter, not a user
     * setting: readers asked that reversals stay on/off (AUDIT §9 "Declined").
     */
    private const float REVERSAL_TURN_PROBABILITY = 0.27;

    /** How many fresh ids persist() draws before giving up on collisions. */
    private const int ID_ATTEMPTS = 3;

    /** SQLite's message for a duplicate reading id — the only error worth a retry. */
    private const string ID_COLLISION = 'UNIQUE constraint failed: readings.reading_id';

    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly DeckRepository $decks,
        private readonly SpreadRepository $spreads,
        private readonly UserSpreadRepository $userSpreads,
        private readonly CardNameResolver $cardNames,
    ) {}

    /**
     * Generate a randomized reading from a deck (optionally shaped by a spread).
     *
     * A personal spread (`user_spread_id`) shapes the reading only for its owner:
     * anyone else's id, a guest's included, is treated like one that doesn't
     * exist (ignored), as the ids are sequential and would otherwise lend out
     * every user's spreads.
     *
     * @param array<string,mixed> $params Raw request fields.
     * @param bool $isAdmin Whether the caller may draw from a deck still awaiting approval.
     * @throws ApiException When the deck is invalid or the reading can't be saved.
     * @throws RandomException
     * @throws JsonException
     */
    public function generate(array $params, ?int $userId = null, bool $isAdmin = false): Reading
    {
        $number_of_cards = max(1, (int) ($params['number_of_cards'] ?? 1));
        $deck_id         = (int) ($params['deck_id'] ?? 1);
        $use_reversals        = Input::bool($params['use_reversals'] ?? null);
        $use_additional_cards = Input::bool($params['use_additional_cards'] ?? null);
        $spread_id            = $params['spread_id'] ?? null;
        $user_spread_id       = $params['user_spread_id'] ?? null;

        // If a spread was selected, load it. The spread dictates the number of
        // cards drawn (one per position) and is snapshotted into the reading.
        $spread = null;
        if ($spread_id !== null && $spread_id !== '' && (int) $spread_id > 0) {
            $loaded = $this->spreads->get((int) $spread_id);
            if ($loaded instanceof Spread) {
                $spread          = $loaded;
                $number_of_cards = max(1, $spread->card_count);
            }
        } elseif ($userId !== null && $user_spread_id !== null && $user_spread_id !== '' && (int) $user_spread_id > 0) {
            $loaded = $this->userSpreads->get($userId, (int) $user_spread_id);
            if ($loaded instanceof UserSpread) {
                // Wrap user spread into the same shape used for snapshots.
                $spread          = $this->userSpreadAsSpread($loaded);
                $number_of_cards = max(1, $spread->card_count);
            }
        }

        $deck = $this->deckForNewReading($deck_id, $isAdmin);

        $total_cards = $deck->getEffectiveTotalCards();

        // If flag was sent, use additional cards. Default is 0 so mistakes won't affect outcome.
        if ($use_additional_cards) {
            $total_cards += $deck->additional_cards;
        }

        // Never draw (or allocate work for) more cards than the deck holds.
        $number_of_cards = min($number_of_cards, max(1, $total_cards));

        // Generate the deck, then shuffle it with a single cryptographically
        // secure Fisher–Yates pass, and take the draw from the top.
        $deck_of_cards = $this->secureShuffle(range(1, $total_cards));
        $draw          = array_slice($deck_of_cards, 0, $number_of_cards);

        // Determine each card's orientation. When reversals are enabled we model
        // the reader's "cut and turn" over the drawn cards so orientations arrive
        // in realistic runs; otherwise every card stays upright.
        $reversal_values = $use_reversals
            ? $this->cutAndTurn($number_of_cards)
            : array_fill(0, $number_of_cards, false);

        // Resolve every drawn card's display name in at most two batched queries.
        $card_names = $this->cardNames->resolve($deck, $draw);

        $reading_data            = [];
        $reading_data['deck_id'] = $deck_id;
        // Marks this as a randomly-generated reading (free draw or spread), which
        // — unlike a hand-authored custom reading — the owner may later draw
        // additional cards into.
        $reading_data['origin'] = 'generated';

        // Snapshot the spread layout so shared reading links render correctly
        // even if the spread is later edited or deleted.
        if ($spread instanceof Spread) {
            $reading_data['spread'] = $this->buildSpreadSnapshot($spread, $params['position_titles'] ?? null);
        }

        $reading_data['draw'] = [];
        foreach ($draw as $key => $card_id) {
            $reading_data['draw'][$key]['card_id']  = $card_id;
            $reading_data['draw'][$key]['reversed'] = $reversal_values[$key];

            if (isset($card_names[$card_id])) {
                $reading_data['draw'][$key]['card_name'] = $card_names[$card_id];
            }
        }

        return $this->persist($reading_data, $this->buildOwnerOptions($userId, $params));
    }

    /**
     * Draw additional cards into an existing generated reading and append them
     * to its draw, never re-dealing a card already present. Returns the updated
     * reading_info array for the caller to persist; it does not save anything
     * itself (so the controller can scope the write to the owner).
     *
     * The reading's own deck is used even if it has since lost its approval:
     * the owner already has the reading, and its deck id is no probe.
     *
     * The new cards are appended to the end of the draw. For a spread reading
     * they have no position yet — the client opens the placement editor to slot
     * them in; for a free draw they simply extend the list.
     *
     * @param array<string,mixed> $info   The reading's decoded reading_info.
     * @param array<string,mixed> $params Raw request fields (count + options).
     * @return array<string,mixed> The updated reading_info.
     * @throws ApiException When the deck is invalid or the deck is exhausted.
     * @throws RandomException
     */
    public function drawAdditional(array $info, array $params): array
    {
        $count                = max(1, (int) ($params['count'] ?? 1));
        $use_reversals        = Input::bool($params['use_reversals'] ?? null);
        $use_additional_cards = Input::bool($params['use_additional_cards'] ?? null);

        $deck = $this->decks->get((int) ($info['deck_id'] ?? 0));
        if (!($deck instanceof Deck)) {
            throw new ApiException('InvalidDeckID', 400);
        }

        $total_cards = $deck->getEffectiveTotalCards();
        if ($use_additional_cards) {
            $total_cards += $deck->additional_cards;
        }

        $existing = is_array($info['draw'] ?? null) ? $info['draw'] : [];

        // A physical deck holds each card once, so exclude cards already dealt.
        $used = [];
        foreach ($existing as $entry) {
            if (isset($entry['card_id'])) {
                $used[(int) $entry['card_id']] = true;
            }
        }

        $pool = [];
        for ($id = 1; $id <= $total_cards; $id++) {
            if (!isset($used[$id])) {
                $pool[] = $id;
            }
        }

        if ($pool === []) {
            throw new ApiException('No more cards are left to draw from this deck.', 409);
        }

        $draw = array_slice($this->secureShuffle($pool), 0, $count);
        $n    = count($draw);

        $reversal_values = $use_reversals ? $this->cutAndTurn($n) : array_fill(0, $n, false);
        $card_names      = $this->cardNames->resolve($deck, $draw);

        foreach ($draw as $key => $card_id) {
            $entry = [
                'card_id'  => $card_id,
                'reversed' => $reversal_values[$key],
            ];
            if (isset($card_names[$card_id])) {
                $entry['card_name'] = $card_names[$card_id];
            }
            $existing[] = $entry;
        }

        $info['draw'] = array_values($existing);

        return $info;
    }

    /**
     * Create a user-authored reading: the visitor chooses the exact card,
     * orientation, title and on-canvas position for each slot (e.g. to record a
     * physical reading). Stored in the same shape as a generated reading so
     * ReadingView renders it unchanged. Card names are resolved server-side and
     * never trusted from the client.
     *
     * @param array<string,mixed> $params Raw request fields.
     * @param bool $isAdmin Whether the caller may use a deck still awaiting approval.
     * @throws ApiException On any validation failure or save error.
     * @throws RandomException
     * @throws JsonException
     */
    public function createCustom(array $params, ?int $userId = null, bool $isAdmin = false): Reading
    {
        $deck_id = (int) ($params['deck_id'] ?? 0);

        $name = Input::string($params['name'] ?? null, 100);
        if ($name === '') {
            $name = 'Custom Reading';
        }

        // The cards payload may arrive as a decoded array (JSON body) or a JSON
        // string (urlencoded body); accept either.
        $cards = $params['cards'] ?? [];
        if (is_string($cards)) {
            $decoded = json_decode($cards, true);
            $cards   = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($cards) || count($cards) === 0) {
            throw new ApiException('At least one card is required.', 400);
        }

        $deck = $this->deckForNewReading($deck_id, $isAdmin);

        $available = max(1, $deck->getEffectiveTotalCards() + $deck->additional_cards);

        // Never store more cards than the deck physically holds.
        $cards = array_slice(array_values($cards), 0, $available);

        // First pass: sanitize each entry and collect the card IDs to resolve.
        $entries  = [];
        $seen_ids = [];

        foreach ($cards as $idx => $card) {
            if (!is_array($card)) {
                throw new ApiException('Invalid card data.', 400);
            }

            $card_id = (int) ($card['card_id'] ?? 0);
            if ($card_id < 1 || $card_id > $available) {
                throw new ApiException('A selected card is not part of this deck.', 400);
            }

            // A physical deck holds each card once, so disallow duplicates.
            if (isset($seen_ids[$card_id])) {
                throw new ApiException('Each card can only be used once in a reading.', 400);
            }
            $seen_ids[$card_id] = true;

            $rotation = (int) ($card['rotation'] ?? 0) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }

            $entries[] = [
                'order'    => $idx + 1,
                'card_id'  => $card_id,
                'reversed' => Input::bool($card['reversed'] ?? null),
                'title'    => Input::string($card['title'] ?? null, 100),
                'x'        => max(0.0, min(100.0, round((float) ($card['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float) ($card['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];
        }

        // Resolve display names in at most two batched queries.
        $card_names = $this->cardNames->resolve($deck, array_keys($seen_ids));

        // Build the spread snapshot + ordered draw.
        $positions = [];
        $draw      = [];

        foreach ($entries as $key => $entry) {
            $card_id   = $entry['card_id'];
            $card_name = $card_names[$card_id] ?? null;

            if ($card_name === null) {
                throw new ApiException('A selected card could not be found.', 400);
            }

            $positions[] = [
                'order'    => $entry['order'],
                'title'    => $entry['title'],
                'x'        => $entry['x'],
                'y'        => $entry['y'],
                'rotation' => $entry['rotation'],
            ];

            $draw[$key] = [
                'card_id'   => $card_id,
                'reversed'  => $entry['reversed'],
                'card_name' => $card_name,
            ];
        }

        $reading_data = [
            'deck_id' => $deck_id,
            // Hand-authored: excluded from the post-hoc "draw more cards" flow.
            'origin'  => 'custom',
            'spread'  => [
                'spread_id'   => 0,
                'name'        => $name,
                'description' => '',
                'positions'   => $positions,
            ],
            'draw'    => $draw,
        ];

        return $this->persist($reading_data, $this->buildOwnerOptions($userId, $params), 500);
    }

    /**
     * The deck a new reading may use: an approved one, or for an admin one still
     * awaiting approval too. Anything else is refused exactly as an unknown id
     * is, so a pending submission can't be found here by probing deck ids any
     * more than through GET /decks/{id}.
     *
     * @throws ApiException
     */
    private function deckForNewReading(int $deckId, bool $isAdmin): Deck
    {
        $deck = $this->decks->get($deckId);
        if (!($deck instanceof Deck) || (!$deck->approved && !$isAdmin)) {
            throw new ApiException('InvalidDeckID', 400);
        }

        return $deck;
    }

    /**
     * Resolve the owner-only options (name, notes, hidden author, the journal
     * fields, who can open it) from a request. These are honored only when a
     * user is logged in ($userId set); a guest's are ignored, so their reading
     * is anonymous and open by link. A guest may instead choose an expiry
     * (`expires_in_days`), which a signed-in reader's reading never gets.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function buildOwnerOptions(?int $userId, array $params): array
    {
        if ($userId === null) {
            return ['expires_at' => ReadingInput::expiresAt($params['expires_in_days'] ?? null)];
        }

        $access = ReadingInput::access($params);

        return [
            'user_id'       => $userId,
            'hide_user'     => Input::bool($params['hide_user'] ?? null),
            'reading_name'  => Input::nullableString($params['reading_name'] ?? null, 100),
            // Notes keep internal whitespace (only the empty check is trimmed).
            'reading_notes' => Input::nullableString($params['reading_notes'] ?? null, 20000, trim: false),
            'question'      => ReadingInput::question($params['question'] ?? null),
            'querent'       => ReadingInput::querent($params['querent'] ?? null),
            'tags'          => ReadingInput::tags($params['tags'] ?? null),
            'is_private'    => $access['is_private'] ?? false,
            'password_hash' => $access['password_hash'] ?? null,
        ];
    }

    /**
     * Build a spread snapshot, applying any custom position titles. Titles
     * arrive as a JSON array aligned with positions sorted by order; a blank
     * entry keeps the spread's default title for that position.
     *
     * Position descriptions come along, so a shared reading shows what each
     * position asks. A retitled position drops its description: it was written
     * for the spread's own title, and the reader has made the position theirs.
     *
     * @return array<string,mixed>
     */
    private function buildSpreadSnapshot(Spread $spread, mixed $rawTitles): array
    {
        $positions = $spread->positions;

        $custom_titles = [];
        if (is_string($rawTitles) && $rawTitles !== '') {
            $decoded = json_decode($rawTitles, true);
            if (is_array($decoded)) {
                $custom_titles = $decoded;
            }
        }

        usort($positions, static fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        foreach ($positions as $idx => $pos) {
            $custom = $custom_titles[$idx] ?? null;
            if (is_string($custom)) {
                $custom = Input::string($custom, 100);
                if ($custom !== '' && $custom !== ($pos['title'] ?? '')) {
                    $positions[$idx]['title'] = $custom;
                    unset($positions[$idx]['description']);
                }
            }
        }

        return [
            'spread_id'   => $spread->spread_id,
            'name'        => $spread->name,
            'description' => $spread->description,
            'positions'   => $positions,
        ];
    }

    /**
     * Wrap a UserSpread into a Spread structure so the reading engine can treat
     * personal spreads identically to public ones.
     */
    private function userSpreadAsSpread(UserSpread $userSpread): Spread
    {
        return new Spread([
            'spread_id'   => 0, // not a public spread
            'name'        => $userSpread->name,
            'description' => $userSpread->description,
            'card_count'  => $userSpread->card_count,
            'positions'   => $userSpread->positions,
        ]);
    }

    /**
     * Wrap reading data in a {@see Reading}, assign an id, and save it.
     *
     * @param array<string,mixed> $reading_data
     * @param array<string,mixed> $owner
     * @throws ApiException When the save fails.
     * @throws JsonException
     * @throws RandomException
     */
    private function persist(array $reading_data, array $owner = [], int $failureStatus = 404): Reading
    {
        $reading = new Reading();
        $reading->setReadingInfo(json_encode($reading_data, JSON_THROW_ON_ERROR));

        if (isset($owner['user_id'])) {
            $reading->setUserId((int) $owner['user_id']);
            $reading->setHideUser((bool) ($owner['hide_user'] ?? false));
            $reading->setReadingName($owner['reading_name'] ?? null);
            $reading->setReadingNotes($owner['reading_notes'] ?? null);
            $reading->setQuestion($owner['question'] ?? null);
            $reading->setQuerent($owner['querent'] ?? null);
            $reading->setTags($owner['tags'] ?? []);
            $reading->setVisibility(match (true) {
                ($owner['is_private'] ?? false) === true => 'private',
                ($owner['password_hash'] ?? null) !== null => 'password',
                default => 'link',
            });
        }
        $reading->setExpiresAt($owner['expires_at'] ?? null);

        // Ids are 40 random bits: a collision is rare, but it used to surface as
        // a 500. Draw a fresh id and retry; any other database error, or a
        // collision on the last attempt, propagates as before (AUDIT §1.6).
        $saved = null;
        for ($attempt = 1;; $attempt++) {
            $reading->setReadingId(bin2hex(random_bytes(5)));
            try {
                $saved = $this->readings->save($reading, $owner['password_hash'] ?? null);
                break;
            } catch (PDOException $e) {
                if ($attempt >= self::ID_ATTEMPTS || !str_contains($e->getMessage(), self::ID_COLLISION)) {
                    throw $e;
                }
            }
        }

        if (!($saved instanceof Reading)) {
            throw new ApiException('ErrorGeneratingReading', $failureStatus);
        }

        return $saved;
    }

    /**
     * Fisher–Yates shuffle backed by random_int() (CSPRNG). A single pass
     * produces a uniformly random permutation.
     *
     * @param  int[] $array
     * @return int[]
     * @throws RandomException
     */
    private function secureShuffle(array $array): array
    {
        for ($i = count($array) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
        }

        return $array;
    }

    /**
     * Model a reader's "cut and turn": split the deck into a few contiguous
     * packets at random boundaries and turn each one (flip the orientation of
     * every card in it) on a weighted coin flip. Returns a per-position array of
     * booleans where true means the card ended up reversed.
     *
     * Reversals therefore arrive in realistic runs rather than independently,
     * while the per-card rate tracks REVERSAL_TURN_PROBABILITY.
     *
     * @return bool[]
     * @throws RandomException
     */
    private function cutAndTurn(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        // Every card starts upright; a turned packet flips its cards.
        $orientations = array_fill(0, $count, false);

        // Split into a handful of packets at random, unique boundaries. Aim for
        // 3–6 cuts, but a very small packet can't support that many: with ≤3
        // possible boundaries take them all, and only randomize once there's
        // genuine room (keeps the random bounds provably ordered, too).
        $max_cuts = min($count - 1, 6);
        if ($max_cuts <= 0) {
            $cut_count = 0;
        } elseif ($max_cuts <= 3) {
            $cut_count = $max_cuts;
        } else {
            $cut_count = random_int(3, $max_cuts);
        }

        $cuts = [];
        while (count($cuts) < $cut_count) {
            $cuts[random_int(1, $count - 1)] = true;
        }
        $boundaries = array_keys($cuts);
        sort($boundaries);
        $boundaries[] = $count; // final packet ends at the bottom of the deck

        // Integer threshold for a CSPRNG coin flip at the configured rate.
        $threshold = (int) round(self::REVERSAL_TURN_PROBABILITY * 1_000_000);

        $start = 0;
        foreach ($boundaries as $end) {
            if (random_int(1, 1_000_000) <= $threshold) {
                for ($i = $start; $i < $end; $i++) {
                    $orientations[$i] = true;
                }
            }
            $start = $end;
        }

        return $orientations;
    }
}
