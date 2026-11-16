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
 * (swagger-php) can assemble `backend/openapi.json`, which the Swagger UI page at
 * `GET /api/docs` renders. Per-operation attributes live on the controllers; the
 * response/request schemas are the `#[OA\Schema]`-annotated Structure classes.
 */
#[OAS\Info(
    version: '2.0.0',
    title: 'TarotGen.io API',
    description: <<<'MD'
        The backend for **TarotGen.io** — a PHP 8.5 / Slim 4 hybrid-REST API.

        - Resources are plural nouns (`/decks`, `/readings`); items are `/{id}`.
        - `GET` reads, `POST` creates (→`201`) or runs a command, `PUT` replaces,
          `PATCH` applies a partial update / flag toggle, `DELETE` removes (→`204`).
        - State transitions that aren't a plain field-set are `POST …/{id}/{verb}`
          (e.g. `…/finalize`, `…/approve`, `…/draw`).
        - Every error is RFC 9457 problem details (`application/problem+json`, the
          `ProblemDetails` schema), whatever the request's `Accept`: `detail` says
          why, in words to show the user. A failed validation answers `422` and lists
          each field it refused in `errors` (`message`, and `location` such as
          `body.email` or `query.limit`); `400` is only for a body that isn't a JSON
          object, and for a plugin code exchange's `invalid_grant` (RFC 6749). `401`
          is not signed in, `403` signed in but not allowed, `404` an
          unknown id or one the requester may not see, `409` a conflict, and `429` a
          rate limit, with `Retry-After`.
        - Auth is a session cookie (`__Host-tarotgen`) set by the `/auth/*` endpoints —
          there are no API keys or bearer tokens for first-party calls.
        MD
)]
#[OAS\Server(url: '/api', description: 'Same-origin API base path')]
#[OAS\Security\Scheme(
    component: 'sessionCookie',
    type: 'apiKey',
    in: 'cookie',
    name: '__Host-tarotgen',
    description: 'Session cookie issued on sign-in: HttpOnly, SameSite=Lax, Secure, and bound to this exact host by its `__Host-` prefix. Sent automatically by the browser.'
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
        new OAS\Property(property: 'reading_time', schema: new OAS\Schema(description: 'When it was made, in UTC.', type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z'])),
        new OAS\Property(property: 'reading_name', schema: new OAS\Schema(description: "The owner's title for it, if any.", type: 'string', nullable: true)),
        new OAS\Property(property: 'reading_notes', schema: new OAS\Schema(description: "The owner's notes (Markdown), if any.", type: 'string', nullable: true)),
        new OAS\Property(property: 'reader', schema: new OAS\Schema(description: '"Guest", or the owner\'s display name unless they chose to hide it.', type: 'string')),
        new OAS\Property(property: 'is_owner', schema: new OAS\Schema(description: 'Whether the requester owns it.', type: 'boolean')),
        new OAS\Property(property: 'is_final', schema: new OAS\Schema(description: 'Locked for good against further draws.', type: 'boolean')),
        new OAS\Property(property: 'can_draw_more', schema: new OAS\Schema(description: 'The requester may draw more cards into it: their own, not final, not custom.', type: 'boolean')),
        new OAS\Property(property: 'locked', schema: new OAS\Schema(description: 'Always false here; see LockedReading.', type: 'boolean', enum: [false])),
        new OAS\Property(property: 'visibility', schema: new OAS\Schema(description: 'Who can open it: anyone with the link, anyone with its password, or (private) only its owner.', type: 'string', enum: ['link', 'password', 'private'])),
        new OAS\Property(property: 'expires_at', schema: new OAS\Schema(description: 'When this guest reading will be deleted, in UTC, as its maker chose; null for the usual retention.', type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z'])),
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
        new OAS\Property(property: 'exported_at', schema: new OAS\Schema(description: 'When the file was made, in UTC.', type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z'])),
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
        new OAS\Property(property: 'reading_time', schema: new OAS\Schema(description: 'When it was made, in UTC.', type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z'])),
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
        new OAS\Property(property: 'created_at', schema: new OAS\Schema(description: 'When it was registered, in UTC.', type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z'])),
        new OAS\Property(property: 'last_used_at', schema: new OAS\Schema(description: 'Always null: it has not signed in yet.', type: 'string', format: 'date-time', nullable: true)),
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
                    new OAS\Property(property: 'date', schema: new OAS\Schema(description: 'The UTC day.', type: 'string', format: 'date', examples: ['2026-11-07'])),
                    new OAS\Property(property: 'count', schema: new OAS\Schema(type: 'integer')),
                ]
            )
        )),
    ]
)]
// Every error the API sends (Routes\Problem). ProblemResponses gives each
// operation's 4xx and 5xx responses this schema.
#[OAS\Schema(
    component: 'ProblemDetails',
    description: 'An error, as RFC 9457 problem details: what every 4xx and 5xx answers with, as application/problem+json.',
    required: ['type', 'title', 'status', 'detail'],
    properties: [
        new OAS\Property(property: 'type', schema: new OAS\Schema(description: 'Always about:blank: the status says what kind of problem it is.', type: 'string', enum: ['about:blank'])),
        new OAS\Property(property: 'title', schema: new OAS\Schema(description: 'The status\'s reason phrase, e.g. "Unprocessable Entity".', type: 'string')),
        new OAS\Property(property: 'status', schema: new OAS\Schema(description: 'The HTTP status code, repeated.', type: 'integer')),
        new OAS\Property(property: 'detail', schema: new OAS\Schema(description: 'Why the request failed, in words to show the user.', type: 'string')),
        new OAS\Property(property: 'errors', schema: new OAS\Schema(description: 'On a failed validation (422) only: each field that was refused.', type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/ProblemFieldError'))),
        new OAS\Property(property: 'exception', schema: new OAS\Schema(
            description: 'Outside production, on an unexpected error (500) only: the exception chain, outermost first.',
            type: 'array',
            items: new OAS\Schema\Items(
                type: 'object',
                required: ['type', 'code', 'message', 'file', 'line'],
                properties: [
                    new OAS\Property(property: 'type', schema: new OAS\Schema(description: "The exception's class.", type: 'string')),
                    new OAS\Property(property: 'code', schema: new OAS\Schema(description: "The exception's code.", type: ['integer', 'string'])),
                    new OAS\Property(property: 'message', schema: new OAS\Schema(description: "The exception's message.", type: 'string')),
                    new OAS\Property(property: 'file', schema: new OAS\Schema(description: 'The file it was thrown in.', type: 'string')),
                    new OAS\Property(property: 'line', schema: new OAS\Schema(description: 'The line it was thrown at.', type: 'integer')),
                ]
            )
        )),
    ]
)]
#[OAS\Schema(
    component: 'ProblemFieldError',
    description: 'One field a failed validation refused.',
    required: ['message', 'location'],
    properties: [
        new OAS\Property(property: 'message', schema: new OAS\Schema(description: 'Why the field was refused, in words to show beside it.', type: 'string')),
        new OAS\Property(property: 'location', schema: new OAS\Schema(description: 'Where the field was sent: `body.email`, `body.positions[2].x`, `query.limit`, `path.deck_id`, or `body` for the body as a whole.', type: 'string')),
    ]
)]
#[OAS\Schema(
    component: 'DailyCardTaken',
    description: 'The problem a second daily card for one day answers with (409).',
    allOf: [
        new OAS\Schema(ref: '#/components/schemas/ProblemDetails'),
        new OAS\Schema(
            type: 'object',
            required: ['reading_id'],
            properties: [
                new OAS\Property(property: 'reading_id', schema: new OAS\Schema(description: 'The reading already drawn for the day; null when it was deleted.', type: 'string', nullable: true)),
            ]
        ),
    ]
)]
#[OAS\Schema(
    component: 'InvalidGrant',
    description: "The problem a plugin's code exchange answers with when the code or verifier is wrong (400): RFC 6749's invalid_grant, which `error` names for an OAuth client.",
    allOf: [
        new OAS\Schema(ref: '#/components/schemas/ProblemDetails'),
        new OAS\Schema(
            type: 'object',
            required: ['error'],
            properties: [
                new OAS\Property(property: 'error', schema: new OAS\Schema(description: 'The OAuth error code.', type: 'string', enum: ['invalid_grant'])),
            ]
        ),
    ]
)]
final class OpenApiSpec
{
    /**
     * The API document as `composer docs` writes it to openapi.json and
     * OpenApiSpecTest compares it: the attributes in api/ and includes/, as
     * OpenAPI 3.1, with every Structure property required and every error
     * response a problem details body. The caller passes the
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
                $augmenters->add(new ProblemResponses());
            });
    }
}
