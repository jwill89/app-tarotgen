<?php

namespace Tarot\Data;

/**
 * What My Readings narrows a user's readings to (ReadingData::listByUser() and
 * countByUser()). Every field is optional and they combine with AND; an empty
 * filter matches every reading. Values arrive checked and normalised
 * (ReadingInput::filter()), so this only carries them.
 */
final readonly class ReadingFilter
{
    /**
     * @param string|null $text   Matched anywhere in the name, notes, question or querent, ignoring ASCII case.
     * @param int|null    $deckId The deck the cards were drawn from.
     * @param string|null $spread The spread's name as the reading recorded it, ignoring ASCII case.
     *                            "Free Draw" also matches a free draw that was never placed, as the
     *                            reading page names one.
     * @param string|null $tag    One tag, normalised as stored.
     * @param string|null $from   Made at or after this UTC time ("Y-m-d H:i:s").
     * @param string|null $until  Made before this UTC time.
     * @param bool|null   $daily  true: only daily cards; false: none; null: either.
     */
    public function __construct(
        public ?string $text = null,
        public ?int $deckId = null,
        public ?string $spread = null,
        public ?string $tag = null,
        public ?string $from = null,
        public ?string $until = null,
        public ?bool $daily = null,
    ) {}
}
