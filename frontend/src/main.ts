import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { markAuthChecked } from './router'
import { useUser } from './composables/useUser'
import { FontAwesomeIcon, FontAwesomeLayers } from '@fortawesome/vue-fontawesome'

// Modular Bulma (only the modules we use) plus the 41 helper classes we kept —
// see assets/bulma.scss for what's deliberately left out.
import './assets/bulma.scss'
import './assets/bulma-helpers.css'
import './assets/tokens.css'
import './assets/fonts.css'
import './assets/style.css'
import './assets/print.css'
// Self-hosted FontAwesome: imports the icon set + core CSS and exposes the
// byPrefixAndName lookup used by the <FontAwesomeIcon> components below.
import './fontawesome'

const app = createApp(App)
app.use(router)

// Last line of defense: log unhandled render/setup errors instead of letting a
// single failing component (e.g. a <RouterLink> to an unregistered route) throw
// out of mount and blank the entire app.
app.config.errorHandler = (err, _instance, info) => {
  console.error(`[app] Unhandled error during ${info}:`, err)
}

// Register the FA Vue components globally so templates can use them without a
// per-file import (icons are passed via :icon="byPrefixAndName.xxx['name']").
app.component('FontAwesomeIcon', FontAwesomeIcon)
app.component('FontAwesomeLayers', FontAwesomeLayers)

// Register the service worker (production only) to enable "Add to Home Screen".
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      /* non-fatal */
    })
  })
}

/** Fade out and remove the index.html boot loader once the app is on screen. */
function hideBootLoader(): void {
  const el = document.getElementById('app-loading')
  if (!el) {
    return
  }
  el.classList.add('is-hiding')
  el.addEventListener('transitionend', () => el.remove(), { once: true })
  // Fallback in case the transition is skipped (e.g. reduced-motion / no repaint).
  setTimeout(() => el.remove(), 600)
}

// Revalidate any existing user session. useUser seeds currentUser from session
// storage, so guards already have a verdict for the common cases; the only
// routes that need the SERVER's verdict before the first navigation are the
// guarded ones (a fresh tab has empty session storage, so a logged-in user
// deep-linking to /account would otherwise be bounced to /login).
//
// For every other route, awaiting this parks the boot loader on a full API
// round-trip (~70-120ms here, far worse on mobile) that changes nothing but the
// navbar. So: start the request now, block on it only where it decides routing.
const { fetchMe } = useUser()

const initialRoute = router.resolve(window.location.pathname + window.location.search)
const authDecidesRouting = initialRoute.matched.some(
  (r) => r.meta.admin === true || r.meta.userOnly === true || r.meta.userGuest === true,
)

// Marked up front, not on settle: the request is in flight, so the router's
// own revalidation should treat the session as freshly checked and not fire a
// duplicate /me during the initial navigation.
markAuthChecked()
const authChecked = fetchMe()

async function boot(): Promise<void> {
  if (authDecidesRouting) {
    await authChecked
  }
  // Wait for the initial route's lazily-loaded component (and its guards) to
  // resolve before mounting, so the app shell and the view paint together
  // rather than the background/navbar flashing in ahead of the content.
  await router.isReady()
  app.mount('#app')
  hideBootLoader()
}

void boot()
