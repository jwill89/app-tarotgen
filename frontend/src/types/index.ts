import type { components } from './api.generated'

type Schemas = components['schemas']

/*
 * Entities the API sends exactly as a backend Structure come from the spec
 * (`npm run gen:types` regenerates api.generated.ts from backend/openapi.json,
 * and CI fails if it is stale), so a field added or retyped in PHP reaches the
 * frontend without a hand copy to update. So do the payloads a controller
 * assembles itself that the spec names as a schema (a reading as viewed, the
 * dashboard's usage stats). Client-side shapes are written by hand further down.
 */
export type User = Schemas['User']
/** A user as the admin Users page lists one: with whether it's protected (PROTECTED_ADMIN_EMAILS). */
export type AdminUser = Schemas['AdminUser']
export type Passkey = Schemas['Passkey']
export type PluginToken = Schemas['PluginToken']
export type Deck = Schemas['Deck']
export type DeckSystem = Schemas['DeckSystem']
export type DeckSystemCard = Schemas['DeckSystemCard']
export type DeckSystemWithCards = Schemas['DeckSystemWithCards']
export type SpecialCard = Schemas['SpecialCard']
export type SpreadPosition = Schemas['SpreadPosition']
export type Spread = Schemas['Spread']
/** A heading the public spread library groups spreads under, as admins keep the list (GET /admin/spread-categories). */
export type SpreadCategory = Schemas['SpreadCategory']
export type PendingSpread = Schemas['PendingSpread']
export type UserSpread = Schemas['UserSpread']
export type FavoriteSpread = Schemas['FavoriteSpread']
export type Contact = Schemas['Contact']
export type CardReportReason = Schemas['CardReportReason']
export type CardReportSubmission = Schemas['CardReportSubmission']
export type CardReport = Schemas['CardReport']

// Readings. `Reading` is a reading as its viewer sees it (GET /readings/{id} and
// the reading commands); `LockedReading` is what that GET answers instead while
// a password stands in the way; `AccountReading` is the stored row the account
// pages list; `ReadingSummary` is a row of the admin readings list.
export type Reading = Schemas['ReadingView']
export type LockedReading = Schemas['LockedReading']
export type AccountReading = Schemas['Reading']
export type ReadingSummary = Schemas['ReadingSummary']
export type ReadingInfo = Schemas['ReadingInfo']
export type DrawCard = Schemas['DrawCard']
/** Who can open a reading: anyone with the link, anyone with its password, or only its owner. */
export type Visibility = Schemas['Reading']['visibility']
/** One of the user's reading tags with how many readings carry it (GET /account/tags). */
export type ReadingTag = Schemas['ReadingTag']
/** A spread the user's readings were laid out in, by name, with how many (GET /account/reading-spreads). */
export type ReadingSpread = Schemas['ReadingSpread']

/** How often each card turned up in the user's readings (GET /account/card-frequency). */
export type CardFrequency = Schemas['CardFrequency']
export type CardCount = Schemas['CardCount']
/** One day's daily card (GET/POST /account/daily-cards). */
export type DailyCard = Schemas['DailyCard']
/** The 409 a second daily card for one day answers with: a problem naming the day's reading. */
export type DailyCardTaken = Schemas['DailyCardTaken']

/** Admin dashboard usage analytics (GET /admin/stats, and `stats` in GET /admin/summary). */
export type UsageStats = Schemas['UsageStats']

// Errors: every one the API sends is RFC 9457 problem details
// (application/problem+json); a failed validation (422) lists its fields.
export type ProblemDetails = Schemas['ProblemDetails']
export type ProblemFieldError = Schemas['ProblemFieldError']

/**
 * A daily card whose reading still exists, so its card is known. A day whose
 * reading was deleted stays drawn, and the API sends it with `reading_id` and
 * every card field null; `isKept()` (utils/calendar) tells the two apart.
 */
export type KeptDailyCard = { [K in keyof DailyCard]: NonNullable<DailyCard[K]> }

/** A selectable card within a deck (id + display name) for the custom reading picker. */
export interface DeckCard {
  card_id: number
  name: string
}

/** Union type used by the spread selector to display both public and user spreads. */
export interface SpreadOption {
  id: string // e.g. "public-3" or "user-12"
  spread_id?: number
  user_spread_id?: number
  name: string
  description: string
  card_count: number
  positions: SpreadPosition[]
  type: 'public' | 'personal'
  isFavorite?: boolean
  /** A public spread's library category, and that category's place in the list. */
  category?: string
  categoryOrder?: number
}

/** What the spread editor loads and saves: a public, personal or submitted spread's fields. */
export interface SpreadDraft {
  name: string
  description: string
  positions: SpreadPosition[]
  /** A public spread's library category (admin only). */
  category_id?: number
}

/**
 * A drawn card ready to show. URLs come from `cardImageUrls()` (utils/cardImages);
 * `card_name` is always set — `cardLabel()` (utils/deck) when the deck has none.
 */
export interface ReadingCard extends DrawCard {
  card_name: string
  /** Full-size display WebP (lightbox, export). */
  imgUrl: string
  /** Master PNG scan — the fallback while a deck's web images aren't generated. */
  pngUrl: string
  thumbUrl: string
}
