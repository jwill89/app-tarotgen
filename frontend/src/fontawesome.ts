/**
 * Self-hosted FontAwesome (Pro v7) wired for use with the Vue component
 * (`@fortawesome/vue-fontawesome`).
 *
 * We deliberately register only the icons actually used in the app, keeping the
 * bundle tiny. Each icon is imported by name (tree-shakeable) and collected into
 * a `byPrefixAndName` lookup keyed by FA prefix + icon name, e.g.
 *
 *     <FontAwesomeIcon :icon="byPrefixAndName.fad['cards-blank']" />
 *
 * The trade-off: when you use a NEW icon in a template, add its import below and
 * include it in `index(...)` — otherwise the lookup is `undefined` and it won't
 * render.
 *
 * Icons come from the per-style packages (@fortawesome/pro-{solid,regular,duotone}-svg-icons
 * + free @fortawesome/free-brands-svg-icons), NOT the monolithic @awesome.me kit.
 * The kit tarball is ~760 MB (every family + format + metadata), so re-pulling it
 * on each cold CI install would exhaust the Font Awesome bandwidth quota; these
 * per-style packages carry only JS path data and install a fraction of that.
 * Still self-hosted — no CDN requests, no pageview/impression metering.
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
  faClockRotateLeft,
  faCode,
  faCookieBite,
  faCopy,
  faCrystalBall,
  faDiamond,
  faEnvelope,
  faEnvelopeCircleCheck,
  faEnvelopeOpen,
  faEye,
  faEyeSlash,
  faFileArrowDown,
  faFloppyDisk,
  faFont,
  faForward,
  faGamepad,
  faGaugeHigh,
  faGear,
  faGlobe,
  faGrid2Plus,
  faHandHoldingMagic,
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
  faLayerGroup,
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
  faSword,
  faTableCells,
  faTags,
  faTrash,
  faTriangleExclamation,
  faUnlock,
  faUser,
  faUserPlus,
  faUserShield,
  faUsers,
  faWandMagicSparkles,
  faXmark,
} from '@fortawesome/pro-solid-svg-icons'

// ── Duotone solid (fad) ─────────────────────────────────────────────────────
import {
  faCalendarDay as fadCalendarDay,
  faCardsBlank as fadCardsBlank,
  faCircleCheck as fadCircleCheck,
  faCircleExclamation as fadCircleExclamation,
  faCircleQuestion as fadCircleQuestion,
  faClockRotateLeft as fadClockRotateLeft,
  faCrystalBall as fadCrystalBall,
  faEnvelope as fadEnvelope,
  faGamepad as fadGamepad,
  faGear as fadGear,
  faGrid2Plus as fadGrid2Plus,
  faHandHoldingMagic as fadHandHoldingMagic,
  faInbox as fadInbox,
  faLayerGroup as fadLayerGroup,
  faRightFromBracket as fadRightFromBracket,
  faScroll as fadScroll,
  faScrollOld as fadScrollOld,
  faSparkles as fadSparkles,
  faTableCells as fadTableCells,
  faUsers as fadUsers,
} from '@fortawesome/pro-duotone-svg-icons'

// ── Regular (far) ───────────────────────────────────────────────────────────
import { faStar as farStar } from '@fortawesome/pro-regular-svg-icons'

// ── Brands (fab) ────────────────────────────────────────────────────────────
import { faGoogle } from '@fortawesome/free-brands-svg-icons'

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
  faClockRotateLeft,
  faCode,
  faCookieBite,
  faCopy,
  faCrystalBall,
  faDiamond,
  faEnvelope,
  faEnvelopeCircleCheck,
  faEnvelopeOpen,
  faEye,
  faEyeSlash,
  faFileArrowDown,
  faFloppyDisk,
  faFont,
  faForward,
  faGamepad,
  faGaugeHigh,
  faGear,
  faGlobe,
  faGrid2Plus,
  faHandHoldingMagic,
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
  faLayerGroup,
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
  faSword,
  faTableCells,
  faTags,
  faTrash,
  faTriangleExclamation,
  faUnlock,
  faUser,
  faUserPlus,
  faUserShield,
  faUsers,
  faWandMagicSparkles,
  faXmark,
  // duotone (fad)
  fadCalendarDay,
  fadCardsBlank,
  fadCircleCheck,
  fadCircleExclamation,
  fadCircleQuestion,
  fadClockRotateLeft,
  fadCrystalBall,
  fadEnvelope,
  fadGamepad,
  fadGear,
  fadGrid2Plus,
  fadHandHoldingMagic,
  fadInbox,
  fadLayerGroup,
  fadRightFromBracket,
  fadScroll,
  fadScrollOld,
  fadSparkles,
  fadTableCells,
  fadUsers,
  // regular (far)
  farStar,
  // brands (fab)
  faGoogle,
])
