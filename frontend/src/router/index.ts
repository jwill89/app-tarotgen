import { createRouter, createWebHistory } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { applyRouteSeo } from '@/utils/seo'
import { routes } from './routes'

const BASE_TITLE = 'TarotGen.io Tarot Generator'

/**
 * Minimum interval (ms) between auth revalidation checks to avoid hammering
 * the server on rapid navigation.
 */
const AUTH_CHECK_INTERVAL = 60_000 // 1 minute
let lastAuthCheck = 0

/** Call after an external auth check (e.g. app boot fetchMe) to avoid duplicate checks. */
export function markAuthChecked(): void {
  lastAuthCheck = Date.now()
}

const router = createRouter({
  history: createWebHistory(),
  // Its replacement (ScrollRestoration, vue-router/experimental) is still
  // experimental and would also change Back from "top" to "where you were":
  // an owner's decision, deferred in AUDIT §7.3.
  // eslint-disable-next-line @typescript-eslint/no-deprecated
  scrollBehavior() {
    return { top: 0 }
  },
  routes,
})

router.afterEach((to, _from, failure) => {
  // A navigation a page stopped (unsaved changes kept it) never arrived: the
  // title and meta stay the page's own.
  if (failure) {
    return
  }

  const pageTitle = to.meta.title as string | undefined
  document.title = pageTitle ? `${pageTitle} | ${BASE_TITLE}` : BASE_TITLE

  // Keep description, canonical and robots tags in sync with the route, so
  // crawlers (and Google's rendered index) don't see the homepage meta on
  // every page. Reading pages are also server-noindexed via og.php.
  applyRouteSeo(
    { description: to.meta.description as string | undefined, noindex: to.meta.noindex === true },
    to.path,
  )
})

router.beforeEach(async (to) => {
  const { currentUser, isLoggedIn, fetchMe } = useUser()

  // Revalidate the session against the server when the user appears logged in
  // and we haven't checked recently. This prevents stale UI after session expiry.
  if (isLoggedIn.value && Date.now() - lastAuthCheck > AUTH_CHECK_INTERVAL) {
    await fetchMe()
    lastAuthCheck = Date.now()
  }

  // Admin pages require an authenticated account with the is_admin flag.
  if (to.meta.admin && !currentUser.value?.is_admin) {
    return isLoggedIn.value ? { name: 'home' } : { name: 'login', query: { redirect: to.fullPath } }
  }

  // Logged-in users shouldn't see the register/login screens.
  if (to.meta.userGuest && isLoggedIn.value) {
    return { name: 'home' }
  }

  // Account pages require a logged-in user.
  if (to.meta.userOnly && !isLoggedIn.value) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
})

export default router
