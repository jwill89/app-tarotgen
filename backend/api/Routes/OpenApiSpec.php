<?php

namespace Routes;

use OpenApi\Augmenter\Properties;
use OpenApi\Builder;
use OpenApi\Spec as OAS;
use OpenApi\Utils\Pipeline;
use Psr\Log\LoggerInterface;

/**
 * Root OpenAPI document metadata: API info, the same-origin server, the session
 * security scheme, and the tag list that groups operations in the reference UI.
 *
 * This carries no runtime behaviour — it exists purely so `composer docs`
 * (swagger-php) can assemble `backend/openapi.json`, which the Scalar page at
 * `GET /api/docs` renders. Per-operation attributes live on the controllers; the
 * response/request schemas are the `#[OA\Schema]`-annotated Structure classes.
 */
#[OAS\Info(
    version: '1.0.0',
    title: 'TarotGen.io API',
    description: <<<'MD'
        The backend for **TarotGen.io** — a PHP 8.5 / Slim 4 hybrid-REST API.

        - Resources are plural nouns (`/decks`, `/readings`); items are `/{id}`.
        - `GET` reads, `POST` creates (→`201`) or runs a command, `PUT` replaces,
          `PATCH` applies a partial update / flag toggle, `DELETE` removes (→`204`).
        - State transitions that aren't a plain field-set are `POST …/{id}/{verb}`
          (e.g. `…/finalize`, `…/approve`, `…/draw`).
        - Errors are `{ "error": "…" }`; multi-message validation is `{ "errors": […] }`.
        - Auth is a session cookie (`PHPSESSID`) set by the `/auth/*` endpoints —
          there are no API keys or bearer tokens for first-party calls.
        MD
)]
#[OAS\Server(url: '/api', description: 'Same-origin API base path')]
#[OAS\Security\Scheme(
    component: 'sessionCookie',
    type: 'apiKey',
    in: 'cookie',
    name: 'PHPSESSID',
    description: 'Session cookie issued on sign-in. Sent automatically by the browser.'
)]
#[OAS\Security\Scheme(
    component: 'pluginToken',
    type: 'http',
    scheme: 'bearer',
    description: 'Personal access token issued to a linked Dalamud plugin (Authorization: Bearer …).'
)]
#[OAS\Tag(name: 'Config', description: 'Public runtime configuration.')]
#[OAS\Tag(name: 'Decks', description: 'Tarot decks: public browsing and user submissions.')]
#[OAS\Tag(name: 'Deck Systems', description: 'Card systems (e.g. Rider–Waite) a deck can follow.')]
#[OAS\Tag(name: 'Spreads', description: 'Public spread layouts and user submissions.')]
#[OAS\Tag(name: 'Readings', description: 'Generating, viewing, and modifying readings.')]
#[OAS\Tag(name: 'Cards', description: "Card-level actions (e.g. reporting a problem with a card's image).")]
#[OAS\Tag(name: 'Contact', description: 'Public contact-form submissions.')]
#[OAS\Tag(name: 'Authentication', description: 'Registration, sign-in/out, and session.')]
#[OAS\Tag(name: 'Passkeys', description: 'WebAuthn passkey registration and login.')]
#[OAS\Tag(name: 'Account', description: "The signed-in user's self-service area.")]
#[OAS\Tag(name: 'Plugin', description: 'Dalamud plugin account linking (OAuth PKCE), token management, and draw keys for unlinked installs.')]
#[OAS\Tag(name: 'Admin · Dashboard', description: 'Admin session check and usage stats.')]
#[OAS\Tag(name: 'Admin · Decks', description: 'Deck moderation and special cards.')]
#[OAS\Tag(name: 'Admin · Deck Systems', description: 'Deck-system moderation.')]
#[OAS\Tag(name: 'Admin · Spreads', description: 'Public-spread CRUD and the pending queue.')]
#[OAS\Tag(name: 'Admin · Readings', description: 'Reading listing and retention cleanup.')]
#[OAS\Tag(name: 'Admin · Users', description: 'Account administration.')]
#[OAS\Tag(name: 'Admin · Contacts', description: 'Submitted contact messages.')]
#[OAS\Tag(name: 'Admin · Card Reports', description: 'Cards users have reported, with the reasons they gave.')]
// A spread's card slot. It has no Structure of its own (positions are stored as
// JSON on the spread), so it is declared here, in the shape
// Tarot\Data\NormalizesPositions stores: x/y are percentages of the canvas.
#[OAS\Schema(
    component: 'SpreadPosition',
    description: 'One card slot in a spread layout.',
    required: ['order', 'title', 'x', 'y', 'rotation'],
    properties: [
        new OAS\Property(property: 'order', schema: new OAS\Schema(description: '1-based: the nth card drawn goes here.', type: 'integer')),
        new OAS\Property(property: 'title', schema: new OAS\Schema(description: 'The slot\'s label, e.g. "Past".', type: 'string', maxLength: 100)),
        new OAS\Property(property: 'x', schema: new OAS\Schema(description: 'Card centre, % of the canvas width.', type: 'number', maximum: 100, minimum: 0)),
        new OAS\Property(property: 'y', schema: new OAS\Schema(description: 'Card centre, % of the canvas height.', type: 'number', maximum: 100, minimum: 0)),
        new OAS\Property(property: 'rotation', schema: new OAS\Schema(description: 'Degrees, 0–359.', type: 'integer', maximum: 359, minimum: 0)),
        new OAS\Property(property: 'description', schema: new OAS\Schema(description: 'What the position asks about, e.g. "What stands in your way" — never what a card means. Trimmed; absent when it has none.', type: 'string', maxLength: 280)),
    ]
)]
// What a reading holds (its reading_info JSON) and the two shapes the reading
// endpoints answer with. None has a Structure: reading_info is stored as JSON, and
// ReadingController builds the payloads (accessiblePayload, the locked stub).
#[OAS\Schema(
    component: 'DrawCard',
    description: 'One drawn card, in draw order.',
    required: ['card_id', 'reversed'],
    properties: [
        new OAS\Property(property: 'card_id', schema: new OAS\Schema(description: "The card's number in its deck; a deck's extra cards come after its system's cards.", type: 'integer')),
        new OAS\Property(property: 'reversed', schema: new OAS\Schema(description: 'Drawn upside down.', type: 'boolean')),
        new OAS\Property(property: 'card_name', schema: new OAS\Schema(description: 'Absent when the deck has no name for the card (an extra card nobody has named yet).', type: 'string')),
    ]
)]
#[OAS\Schema(
    component: 'SpreadSnapshot',
    description: "The layout as it was when the reading was made, so a shared reading still renders after its spread is edited or deleted.",
    required: ['spread_id', 'name', 'description', 'positions'],
    properties: [
        new OAS\Property(property: 'spread_id', schema: new OAS\Schema(description: 'The public spread it came from; 0 for a personal spread, a custom reading or a free-draw placement.', type: 'integer')),
        new OAS\Property(property: 'name', schema: new OAS\Schema(description: "The spread's name.", type: 'string')),
        new OAS\Property(property: 'description', schema: new OAS\Schema(description: 'Markdown; may be empty.', type: 'string')),
        new OAS\Property(property: 'positions', schema: new OAS\Schema(description: "One per drawn card. A position keeps its spread's description unless the reader retitled it; readings made before descriptions existed have none.", type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
    ]
)]
#[OAS\Schema(
    component: 'ReadingInfo',
    description: 'What was drawn, from which deck, in which layout.',
    required: ['deck_id', 'draw'],
    properties: [
        new OAS\Property(property: 'deck_id', schema: new OAS\Schema(description: 'The deck the cards were drawn from.', type: 'integer')),
        new OAS\Property(property: 'origin', schema: new OAS\Schema(description: "How it was made; a custom reading can't draw more cards. Absent on readings made before this was recorded.", type: 'string', enum: ['generated', 'custom'])),
        new OAS\Property(property: 'spread', schema: new OAS\Schema(ref: '#/components/schemas/SpreadSnapshot', description: 'Absent for a free draw that was never placed.')),
        new OAS\Property(property: 'draw', schema: new OAS\Schema(description: 'The cards, in draw order.', type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DrawCard'))),
    ]
)]
#[OAS\Schema(
    component: 'ReadingView',
    description: 'A reading as the requester may see it: what GET /readings/{reading_id} and the reading commands (generate, unlock, draw, finalize) return.',
    required: ['reading_id', 'reading_info', 'reading_time', 'reading_name', 'reading_notes', 'reader', 'is_owner', 'is_final', 'can_draw_more', 'locked', 'visibility', 'expires_at'],
    properties: [
        new OAS\Property(property: 'reading_id', schema: new OAS\Schema(description: 'The code in the reading link.', type: 'string')),
        new OAS\Property(property: 'reading_info', schema: new OAS\Schema(ref: '#/components/schemas/ReadingInfo', description: 'What was drawn.')),
        new OAS\Property(property: 'reading_time', schema: new OAS\Schema(description: 'When it was made, UTC ("Y-m-d H:i:s").', type: 'string')),
        new OAS\Property(property: 'reading_name', schema: new OAS\Schema(description: "The owner's title for it, if any.", type: 'string', nullable: true)),
        new OAS\Property(property: 'reading_notes', schema: new OAS\Schema(description: "The owner's notes (Markdown), if any.", type: 'string', nullable: true)),
        new OAS\Property(property: 'reader', schema: new OAS\Schema(description: '"Guest", or the owner\'s display name unless they chose to hide it.', type: 'string')),
        new OAS\Property(property: 'is_owner', schema: new OAS\Schema(description: 'Whether the requester owns it.', type: 'boolean')),
        new OAS\Property(property: 'is_final', schema: new OAS\Schema(description: 'Locked for good against further draws.', type: 'boolean')),
        new OAS\Property(property: 'can_draw_more', schema: new OAS\Schema(description: 'The requester may draw more cards into it: their own, not final, not custom.', type: 'boolean')),
        new OAS\Property(property: 'locked', schema: new OAS\Schema(description: 'Always false here; see LockedReading.', type: 'boolean', enum: [false])),
        new OAS\Property(property: 'visibility', schema: new OAS\Schema(description: 'Who can open it: anyone with the link, anyone with its password, or (private) only its owner.', type: 'string', enum: ['link', 'password', 'private'])),
        new OAS\Property(property: 'expires_at', schema: new OAS\Schema(description: 'When this guest reading will be deleted, UTC ("Y-m-d H:i:s"), as its maker chose; null for the usual retention.', type: 'string', nullable: true)),
        new OAS\Property(property: 'question', schema: new OAS\Schema(description: "What was asked. Only in the owner's payload.", type: 'string', nullable: true)),
        new OAS\Property(property: 'querent', schema: new OAS\Schema(description: "Who the reading was for. Only in the owner's payload.", type: 'string', nullable: true)),
        new OAS\Property(property: 'tags', schema: new OAS\Schema(description: "The owner's tags, sorted. Only in the owner's payload.", type: 'array', items: new OAS\Schema\Items(type: 'string'))),
        new OAS\Property(property: 'hide_user', schema: new OAS\Schema(description: "Whether the owner hid their name from it. Only in the owner's payload.", type: 'boolean')),
    ]
)]
#[OAS\Schema(
    component: 'LockedReading',
    description: "Sent instead of the reading to anyone but its owner until they unlock it with its password (POST /readings/{reading_id}/unlock).",
    required: ['locked', 'reading_name'],
    properties: [
        new OAS\Property(property: 'locked', schema: new OAS\Schema(description: 'Always true here.', type: 'boolean', enum: [true])),
        new OAS\Property(property: 'reading_name', schema: new OAS\Schema(description: "The owner's title for it, if any — the only thing shown before unlocking.", type: 'string', nullable: true)),
    ]
)]
// The journal download (GET /account/readings/export?format=json), which
// Tarot\Service\JournalExport writes a reading at a time.
#[OAS\Schema(
    component: 'JournalExport',
    description: "Every reading the user owns: the JSON journal download.",
    required: ['exported_at', 'readings'],
    properties: [
        new OAS\Property(property: 'exported_at', schema: new OAS\Schema(description: 'When the file was made, UTC ("Y-m-d H:i:s").', type: 'string')),
        new OAS\Property(property: 'readings', schema: new OAS\Schema(description: 'Newest first.', type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/JournalReading'))),
    ]
)]
#[OAS\Schema(
    component: 'JournalReading',
    description: 'One reading in the journal download.',
    required: ['reading_id', 'link', 'reading_time', 'reading_name', 'reading_notes', 'question', 'querent', 'tags', 'deck_id', 'deck_name', 'spread', 'cards', 'is_final', 'visibility', 'password_protected'],
    properties: [
        new OAS\Property(property: 'reading_id', schema: new OAS\Schema(description: 'The code in the reading link.', type: 'string')),
        new OAS\Property(property: 'link', schema: new OAS\Schema(description: "The reading's share link.", type: 'string')),
        new OAS\Property(property: 'reading_time', schema: new OAS\Schema(description: 'When it was made, UTC ("Y-m-d H:i:s").', type: 'string')),
        new OAS\Property(property: 'reading_name', schema: new OAS\Schema(description: "The owner's title for it, if any.", type: 'string', nullable: true)),
        new OAS\Property(property: 'reading_notes', schema: new OAS\Schema(description: "The owner's notes (Markdown), if any.", type: 'string', nullable: true)),
        new OAS\Property(property: 'question', schema: new OAS\Schema(description: 'What was asked, if recorded.', type: 'string', nullable: true)),
        new OAS\Property(property: 'querent', schema: new OAS\Schema(description: 'Who the reading was for, if recorded.', type: 'string', nullable: true)),
        new OAS\Property(property: 'tags', schema: new OAS\Schema(description: 'Its tags, sorted.', type: 'array', items: new OAS\Schema\Items(type: 'string'))),
        new OAS\Property(property: 'deck_id', schema: new OAS\Schema(description: 'The deck the cards were drawn from.', type: 'integer')),
        new OAS\Property(property: 'deck_name', schema: new OAS\Schema(description: '"Deck #{deck_id}" for a deck since deleted.', type: 'string')),
        new OAS\Property(property: 'spread', schema: new OAS\Schema(
            description: 'The layout as it was when the reading was made; null for a free draw that was never placed.',
            oneOf: [new OAS\Schema(ref: '#/components/schemas/SpreadSnapshot'), new OAS\Schema(type: 'null')]
        )),
        new OAS\Property(property: 'cards', schema: new OAS\Schema(description: 'In draw order.', type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/JournalCard'))),
        new OAS\Property(property: 'is_final', schema: new OAS\Schema(description: 'Locked for good against further draws.', type: 'boolean')),
        new OAS\Property(property: 'visibility', schema: new OAS\Schema(description: 'Who can open it: anyone with the link, anyone with its password, or (private) only its owner.', type: 'string', enum: ['link', 'password', 'private'])),
        new OAS\Property(property: 'password_protected', schema: new OAS\Schema(description: 'A view password is set (the password itself is never exported).', type: 'boolean')),
    ]
)]
#[OAS\Schema(
    component: 'JournalCard',
    description: 'One drawn card in the journal download.',
    required: ['position', 'position_title', 'card_id', 'card_name', 'reversed'],
    properties: [
        new OAS\Property(property: 'position', schema: new OAS\Schema(description: 'Its place in the draw, from 1; on a spread, the order of the position it lies in.', type: 'integer')),
        new OAS\Property(property: 'position_title', schema: new OAS\Schema(description: "That position's title; null for a free draw, or a card drawn after the layout was placed.", type: 'string', nullable: true)),
        new OAS\Property(property: 'card_id', schema: new OAS\Schema(description: "The card's number in its deck.", type: 'integer')),
        new OAS\Property(property: 'card_name', schema: new OAS\Schema(description: '"Card #{card_id}" when the deck has no name for it.', type: 'string')),
        new OAS\Property(property: 'reversed', schema: new OAS\Schema(description: 'Drawn upside down.', type: 'boolean')),
    ]
)]
#[OAS\Schema(
    component: 'DeckSystemWithCards',
    description: 'A deck system with its card definitions: what viewing one returns (public and admin).',
    allOf: [
        new OAS\Schema(ref: '#/components/schemas/DeckSystem'),
        new OAS\Schema(
            type: 'object',
            required: ['cards'],
            properties: [
                new OAS\Property(property: 'cards', schema: new OAS\Schema(description: 'The named cards, in card order.', type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DeckSystemCard'))),
            ]
        ),
    ]
)]
// What registering a passkey answers with. PasskeyController builds it from what
// it just stored, which is a Passkey less its credential id.
#[OAS\Schema(
    component: 'RegisteredPasskey',
    description: 'The passkey just registered: its row as the account page lists it (Passkey), without credential_id.',
    required: ['passkey_id', 'name', 'created_at', 'last_used_at'],
    properties: [
        new OAS\Property(property: 'passkey_id', schema: new OAS\Schema(type: 'integer')),
        new OAS\Property(property: 'name', schema: new OAS\Schema(description: 'The name given, or "My Passkey".', type: 'string')),
        new OAS\Property(property: 'created_at', schema: new OAS\Schema(description: 'When it was registered, UTC ("Y-m-d H:i:s").', type: 'string')),
        new OAS\Property(property: 'last_used_at', schema: new OAS\Schema(description: 'Always null: it has not signed in yet.', type: 'string', nullable: true)),
    ]
)]
// The admin dashboard's usage overview (GET /admin/stats, and `stats` in
// GET /admin/summary). StatsService aggregates it from the readings table.
#[OAS\Schema(
    component: 'UsageStats',
    description: 'Usage analytics over the stored readings, for the admin dashboard.',
    required: ['totals', 'byType', 'topDecks', 'topSpreads', 'daily'],
    properties: [
        new OAS\Property(property: 'totals', schema: new OAS\Schema(
            description: 'How many readings are stored, and how many of them are recent.',
            type: 'object',
            required: ['readings', 'last7', 'last30'],
            properties: [
                new OAS\Property(property: 'readings', schema: new OAS\Schema(description: 'Every stored reading.', type: 'integer')),
                new OAS\Property(property: 'last7', schema: new OAS\Schema(description: 'Made in the last 7 days.', type: 'integer')),
                new OAS\Property(property: 'last30', schema: new OAS\Schema(description: 'Made in the last 30 days.', type: 'integer')),
            ]
        )),
        new OAS\Property(property: 'byType', schema: new OAS\Schema(
            description: 'Every stored reading, by the layout it was given.',
            type: 'object',
            required: ['freeDraw', 'spread', 'custom'],
            properties: [
                new OAS\Property(property: 'freeDraw', schema: new OAS\Schema(description: 'A free draw never placed in a layout.', type: 'integer')),
                new OAS\Property(property: 'spread', schema: new OAS\Schema(description: 'Laid out in a public spread.', type: 'integer')),
                new OAS\Property(property: 'custom', schema: new OAS\Schema(description: 'Laid out in a personal spread, a custom reading, or a placed free draw (spread_id 0).', type: 'integer')),
            ]
        )),
        new OAS\Property(property: 'topDecks', schema: new OAS\Schema(
            description: 'The (at most 10) decks with the most readings, most first.',
            type: 'array',
            items: new OAS\Schema\Items(
                type: 'object',
                required: ['deck_id', 'name', 'count'],
                properties: [
                    new OAS\Property(property: 'deck_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'name', schema: new OAS\Schema(description: '"Deck #{deck_id}" for a deck deleted since.', type: 'string')),
                    new OAS\Property(property: 'count', schema: new OAS\Schema(description: 'Readings drawn from it.', type: 'integer')),
                ]
            )
        )),
        new OAS\Property(property: 'topSpreads', schema: new OAS\Schema(
            description: 'The (at most 10) public spreads with the most readings, by name, most first.',
            type: 'array',
            items: new OAS\Schema\Items(
                type: 'object',
                required: ['name', 'count'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(description: 'The spread\'s name as the readings recorded it ("Untitled" if none).', type: 'string')),
                    new OAS\Property(property: 'count', schema: new OAS\Schema(description: 'Readings laid out in it.', type: 'integer')),
                ]
            )
        )),
        new OAS\Property(property: 'daily', schema: new OAS\Schema(
            description: 'Readings per UTC day over the last 14 days, oldest first; a day with none is left out.',
            type: 'array',
            items: new OAS\Schema\Items(
                type: 'object',
                required: ['date', 'count'],
                properties: [
                    new OAS\Property(property: 'date', schema: new OAS\Schema(description: '"Y-m-d", UTC.', type: 'string')),
                    new OAS\Property(property: 'count', schema: new OAS\Schema(type: 'integer')),
                ]
            )
        )),
    ]
)]
final class OpenApiSpec
{
    /**
     * The API document as `composer docs` writes it to openapi.json and
     * OpenApiSpecTest compares it: the attributes in api/ and includes/, as
     * OpenAPI 3.1, with every Structure property required. The caller passes the
     * logger it wants the build's messages in (swagger-php 7.3 takes it in the
     * constructor; setLogger() is deprecated) and runs build().
     */
    public static function builder(string $backendDir, ?LoggerInterface $logger = null): Builder
    {
        return new Builder($logger)
            ->setVersion('3.1.0')
            ->addSource([$backendDir . '/api', $backendDir . '/includes'])
            ->withAugmenters(static function (Pipeline $augmenters): void {
                // No `default` from a property's initializer: that's a placeholder
                // for hydration, not a value the API fills in when a field is
                // absent (it always sends every field). Its `required` inference
                // goes with it; RequireStructureProperties covers the Structures.
                $augmenters->remove(Properties::class);
                $augmenters->add(new RequireStructureProperties());
            });
    }
}
