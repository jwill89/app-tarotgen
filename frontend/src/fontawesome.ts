/**
 * Self-hosted Font Awesome Pro (v7), from the site's own kit package, wired for
 * use with the Vue component (`@fortawesome/vue-fontawesome`).
 *
 * Only the icons the app uses are registered, so the bundle carries nothing more.
 * Each icon is imported by name (tree-shakeable) and collected into a
 * `byPrefixAndName` lookup keyed by Font Awesome prefix + icon name, e.g.
 *
 *     <FontAwesomeIcon :icon="byPrefixAndName.fad['cards-blank']" />
 *
 * The trade-off: when you use a NEW icon in a template, add its import below and
 * include it in `index(...)` — otherwise the lookup is `undefined` and it won't
 * render. Every style the app uses (classic solid and regular, duotone solid,
 * brands) is in the kit; a style the kit lacks has no module to import.
 */
import { config } from '@fortawesome/fontawesome-svg-core'
import type { IconDefinition, IconName, IconPrefix } from '@fortawesome/fontawesome-svg-core'
// We import the core CSS ourselves so it's part of the bundle (sizing, spin
// keyframes, fa-layers, etc.). config.autoAddCss=false stops the runtime inject.
import '@fortawesome/fontawesome-svg-core/styles.css'

// ── Solid (fas) ─────────────────────────────────────────────────────────────
import {
  faAlignCenter,
  faAlignLeft,
  faAlignRight,
  faAnglesDown,
  faAnglesUp,
  faArrowLeft,
  faArrowRight,
  faArrowsLeftRight,
  faArrowsRotate,
  faArrowsToDot,
  faArrowsUpDown,
  faBars,
  faBold,
  faBook,
  faBookmark,
  faBroom,
  faCalendarDay,
  faCards,
  faCardsBlank,
  faCartShopping,
  faCheck,
  faChevronDown,
  faChevronLeft,
  faChevronRight,
  faChevronUp,
  faCircleCheck,
  faCircleExclamation,
  faCircleInfo,
  faCirclePlus,
  faCircleQuestion,
  faCircleUser,
  faCode,
  faCookieBite,
  faCopy,
  faCrystalBall,
  faEnvelope,
  faEnvelopeCircleCheck,
  faEnvelopeOpen,
  faEye,
  faEyeSlash,
  faFileArrowDown,
  faFloppyDisk,
  faForward,
  faGaugeHigh,
  faGear,
  faGlobe,
  faHandPointer,
  faHashtag,
  faHeading,
  faHourglassHalf,
  faHouse,
  faImage,
  faImages,
  faInbox,
  faItalic,
  faKey,
  faLink,
  faListOl,
  faListUl,
  faLock,
  faLockKeyhole,
  faLockOpen,
  faMagnifyingGlass,
  faMagnifyingGlassMinus,
  faMagnifyingGlassPlus,
  faPaperPlane,
  faPen,
  faPenToSquare,
  faPlus,
  faPrint,
  faQuoteLeft,
  faRightFromBracket,
  faRightToBracket,
  faRotate,
  faRotateLeft,
  faRotateRight,
  faScroll,
  faShuffle,
  faSort,
  faSortDown,
  faSortUp,
  faSparkles,
  faSpinner,
  faStar,
  faTableCells,
  faTags,
  faTrash,
  faTriangleExclamation,
  faUnlock,
  faUser,
  faUserPlus,
  faUserShield,
  faUsers,
  faXmark,
} from '@awesome.me/kit-91d12dd2d3/icons/classic/solid'

// ── Duotone solid (fad) ─────────────────────────────────────────────────────
import {
  faCalendarDay as fadCalendarDay,
  faCardsBlank as fadCardsBlank,
  faCircleCheck as fadCircleCheck,
  faCircleExclamation as fadCircleExclamation,
  faCircleQuestion as fadCircleQuestion,
  faEnvelope as fadEnvelope,
  faGear as fadGear,
  faGrid2Plus as fadGrid2Plus,
  faInbox as fadInbox,
  faLayerGroup as fadLayerGroup,
  faRightFromBracket as fadRightFromBracket,
  faScroll as fadScroll,
  faSparkles as fadSparkles,
  faTableCells as fadTableCells,
  faUsers as fadUsers,
} from '@awesome.me/kit-91d12dd2d3/icons/duotone/solid'

// ── Regular (far) ───────────────────────────────────────────────────────────
import { faStar as farStar } from '@awesome.me/kit-91d12dd2d3/icons/classic/regular'

// ── Brands (fab) ────────────────────────────────────────────────────────────
import { faGoogle } from '@awesome.me/kit-91d12dd2d3/icons/classic/brands'

// The core would otherwise inject its CSS at runtime; we import it statically
// above so it's part of the bundle (no FOUC, no duplicate injection).
config.autoAddCss = false

/**
 * Index the imported icon definitions into `{ [prefix]: { [iconName]: def } }`.
 * Each IconDefinition already carries its own `prefix` and `iconName`, so we
 * don't have to hand-maintain the kebab-case names.
 *
 * Keyed by Font Awesome's own name union, not any string: a name that is no icon
 * at all fails to type-check, and a lookup reads as an icon rather than as an
 * index that may find nothing. One that is an icon but isn't registered here is
 * still undefined at runtime (the trade-off above).
 */
function index(defs: IconDefinition[]): Record<IconPrefix, Record<IconName, IconDefinition>> {
  // Built up incrementally, so buckets are genuinely absent until first seen.
  const map: Partial<Record<IconPrefix, Partial<Record<IconName, IconDefinition>>>> = {}
  for (const def of defs) {
    ;(map[def.prefix] ??= {})[def.iconName] = def
  }
  return map as Record<IconPrefix, Record<IconName, IconDefinition>>
}

export const byPrefixAndName = index([
  // solid (fas)
  faAlignCenter,
  faAlignLeft,
  faAlignRight,
  faAnglesDown,
  faAnglesUp,
  faArrowLeft,
  faArrowRight,
  faArrowsLeftRight,
  faArrowsRotate,
  faArrowsToDot,
  faArrowsUpDown,
  faBars,
  faBold,
  faBook,
  faBookmark,
  faBroom,
  faCalendarDay,
  faCards,
  faCardsBlank,
  faCartShopping,
  faCheck,
  faChevronDown,
  faChevronLeft,
  faChevronRight,
  faChevronUp,
  faCircleCheck,
  faCircleExclamation,
  faCircleInfo,
  faCirclePlus,
  faCircleQuestion,
  faCircleUser,
  faCode,
  faCookieBite,
  faCopy,
  faCrystalBall,
  faEnvelope,
  faEnvelopeCircleCheck,
  faEnvelopeOpen,
  faEye,
  faEyeSlash,
  faFileArrowDown,
  faFloppyDisk,
  faForward,
  faGaugeHigh,
  faGear,
  faGlobe,
  faHandPointer,
  faHashtag,
  faHeading,
  faHourglassHalf,
  faHouse,
  faImage,
  faImages,
  faInbox,
  faItalic,
  faKey,
  faLink,
  faListOl,
  faListUl,
  faLock,
  faLockKeyhole,
  faLockOpen,
  faMagnifyingGlass,
  faMagnifyingGlassMinus,
  faMagnifyingGlassPlus,
  faPaperPlane,
  faPen,
  faPenToSquare,
  faPlus,
  faPrint,
  faQuoteLeft,
  faRightFromBracket,
  faRightToBracket,
  faRotate,
  faRotateLeft,
  faRotateRight,
  faScroll,
  faShuffle,
  faSort,
  faSortDown,
  faSortUp,
  faSparkles,
  faSpinner,
  faStar,
  faTableCells,
  faTags,
  faTrash,
  faTriangleExclamation,
  faUnlock,
  faUser,
  faUserPlus,
  faUserShield,
  faUsers,
  faXmark,
  // duotone (fad)
  fadCalendarDay,
  fadCardsBlank,
  fadCircleCheck,
  fadCircleExclamation,
  fadCircleQuestion,
  fadEnvelope,
  fadGear,
  fadGrid2Plus,
  fadInbox,
  fadLayerGroup,
  fadRightFromBracket,
  fadScroll,
  fadSparkles,
  fadTableCells,
  fadUsers,
  // regular (far)
  farStar,
  // brands (fab)
  faGoogle,
])
