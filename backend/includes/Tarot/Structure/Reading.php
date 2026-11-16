<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * ($reading->reading_id), writes go through the setters below. PDO's FETCH_CLASS
 * and the array constructor still hydrate rows, while external code cannot mutate
 * the entity — only the Data/Service layer builds one up via the setters.
 */
#[OAS\Schema(description: 'A saved reading as stored — what the account pages and custom-reading creation return. Viewing a reading returns ReadingView instead.')]
class Reading extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $reading_id = '';

    /** Stored as a JSON string; jsonSerialize() sends it as the object itself. */
    #[OAS\Property(schema: new OAS\Schema(ref: '#/components/schemas/ReadingInfo', description: 'What was drawn.'))]
    public private(set) string $reading_info = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $reading_time = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true))]
    public private(set) ?int $user_id = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $hide_user = false;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $reading_name = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $reading_notes = null;

    /** One-way lock: when true the owner can no longer draw additional cards. */
    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $is_final = false;

    /** Derived flag — true when a view password is set. The hash itself is never exposed. */
    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $password_protected = false;

    /**
     * Derived — the local day ("Y-m-d") this reading is the owner's daily card
     * for, or null for any other reading (see DailyCardData).
     */
    #[OAS\Property(schema: new OAS\Schema(description: 'The day ("Y-m-d", the owner\'s local date) this reading is their daily card for; null for any other reading.', type: 'string', format: 'date', nullable: true, examples: ['2026-11-07']))]
    public private(set) ?string $daily_card_day = null;

    /** Owner-only: never in what anyone else is sent (ReadingController, og.php). */
    #[OAS\Property(schema: new OAS\Schema(description: 'What was asked. Only its owner ever sees it.', type: 'string', nullable: true, maxLength: 500))]
    public private(set) ?string $question = null;

    /** Owner-only, as $question. */
    #[OAS\Property(schema: new OAS\Schema(description: 'Who the reading was for, as the owner wrote it. Only its owner ever sees it.', type: 'string', nullable: true, maxLength: 100))]
    public private(set) ?string $querent = null;

    /**
     * The owner's tags, sorted. Read as a JSON array the SELECT builds from
     * reading_tags; the set hook decodes it, as Spread::$positions does.
     *
     * @var list<string>
     */
    #[OAS\Property(schema: new OAS\Schema(description: "The owner's tags: lower-case, sorted. Only its owner ever sees them.", type: 'array', items: new OAS\Schema\Items(type: 'string')))]
    public private(set) array $tags = [] {
        set(array|string $value) {
            $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
            $this->tags = array_values(array_filter(is_array($decoded) ? $decoded : [], is_string(...)));
        }
    }

    /**
     * Derived — who may open it: `private` (is_private, the owner only), else
     * `password` (a view password is set), else `link` (anyone with the link).
     */
    #[OAS\Property(schema: new OAS\Schema(description: 'Who can open it: anyone with the link, anyone with its password, or (private) only its owner.', type: 'string', enum: ['link', 'password', 'private']))]
    public private(set) string $visibility = 'link';

    #[OAS\Property(schema: new OAS\Schema(description: 'When a guest reading will be deleted, in UTC, as its maker chose; null for the usual retention. A reading made signed in never has one.', type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $expires_at = null;

    /** Whether $userId (null for a guest) owns it. */
    public function isOwnedBy(?int $userId): bool
    {
        return $this->user_id !== null && $this->user_id === $userId;
    }

    /**
     * Whether $userId (null for a guest) may open it at all: a private reading
     * only its owner. A password still stands between anyone else and the rest.
     */
    public function isVisibleTo(?int $userId): bool
    {
        return $this->visibility !== 'private' || $this->isOwnedBy($userId);
    }

    public function setReadingId(string $reading_id): self
    {
        $this->reading_id = $reading_id;
        return $this;
    }

    public function setReadingInfo(string $reading_info): self
    {
        $this->reading_info = $reading_info;
        return $this;
    }

    public function setReadingTime(string $reading_time): self
    {
        $this->reading_time = $reading_time;
        return $this;
    }

    public function setUserId(?int $user_id): self
    {
        $this->user_id = $user_id;
        return $this;
    }

    public function setHideUser(bool $hide_user): self
    {
        $this->hide_user = $hide_user;
        return $this;
    }

    public function setReadingName(?string $reading_name): self
    {
        $this->reading_name = $reading_name;
        return $this;
    }

    public function setReadingNotes(?string $reading_notes): self
    {
        $this->reading_notes = $reading_notes;
        return $this;
    }

    public function setQuestion(?string $question): self
    {
        $this->question = $question;
        return $this;
    }

    public function setQuerent(?string $querent): self
    {
        $this->querent = $querent;
        return $this;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    /** `private` makes it owner-only; a password is stored apart (ReadingData::store()). */
    public function setVisibility(string $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function setExpiresAt(?string $expires_at): self
    {
        $this->expires_at = $expires_at;
        return $this;
    }

    /**
     * Override serialization so reading_info is emitted as a decoded JSON object
     * rather than a double-encoded string.
     *
     * @return array<string,mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        // Decode the JSON string so it becomes a nested object in the response.
        $decoded = json_decode($this->reading_info, true, 512, JSON_THROW_ON_ERROR);
        $data['reading_info'] = is_array($decoded) ? $decoded : [];

        return $data;
    }
}
