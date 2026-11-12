import { mount, flushPromises, type ComponentMountingOptions } from '@vue/test-utils'
import { createRouter, createMemoryHistory, createWebHistory, type Router } from 'vue-router'
import { FontAwesomeIcon, FontAwesomeLayers } from '@fortawesome/vue-fontawesome'
import { routes } from '@/router/routes'
import { useUser } from '@/composables/useUser'
import type { User } from '@/types'

export { stubApi } from './api'

/** A router on the app's real route table (names, meta), without its guards. */
export function testRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

/** vi.waitFor's options for a lazily loaded view or component: a cold import can take over 1 s. */
export const lazyLoad = { timeout: 10_000 }

const webRouters = new Set<Router>()

/**
 * testRouter on the window's own history (jsdom's) instead of a memory one,
 * for specs about Back: only there does vue-router keep the previous entry in
 * history.state, and window.history.back() fires the popstate it listens for.
 * Each starts afresh at `/` with no state; cleanup() stops it listening.
 */
export function testWebRouter(): Router {
  window.history.replaceState(null, '', '/')
  const router = createRouter({ history: createWebHistory(), routes })
  webRouters.add(router)
  return router
}

const mounted = new Set<{ unmount(): void }>()

/**
 * Mount as main.ts does: the global FontAwesome components and a router on the
 * real routes (pass `router` to control the current route; it is pushed to
 * `path` first). Attached to document.body, because Reka portals its floating
 * content (combobox lists, dialogs, tooltips) there: find it with
 * `document.body.querySelector`, not the wrapper. Run `cleanup` after each test
 * (`afterEach(cleanup)`) so it leaves nothing behind for the next one.
 */
export async function mountWithApp<T>(
  component: T,
  {
    router = testRouter(),
    path = '/',
    ...options
  }: ComponentMountingOptions<T> & { router?: Router; path?: string } = {},
) {
  await router.push(path)
  await router.isReady()
  const wrapper = mount(component, {
    attachTo: document.body,
    ...options,
    global: {
      ...options.global,
      plugins: [router, ...(options.global?.plugins ?? [])],
      components: { FontAwesomeIcon, FontAwesomeLayers, ...options.global?.components },
    },
  })
  mounted.add(wrapper)
  await flushPromises()
  return { wrapper, router }
}

/**
 * Unmount everything mountWithApp mounted, then clear what was portalled into
 * the body. Unmounting first matters: clearing the body under a live app leaves
 * Vue patching nodes that are gone.
 */
export async function cleanup(): Promise<void> {
  for (const wrapper of mounted) {
    wrapper.unmount()
  }
  mounted.clear()
  for (const router of webRouters) {
    router.options.history.destroy()
  }
  webRouters.clear()
  await flushPromises()
  document.body.innerHTML = ''
}

/** A signed-in user as /auth/me reports one; override what the test cares about. */
export function testUser(overrides: Partial<User> = {}): User {
  return {
    user_id: 7,
    email: 'reader@example.test',
    display_name: 'Reader',
    is_active: true,
    is_admin: false,
    registered_at: '2026-01-01 00:00:00',
    last_login_at: null,
    google_linked: false,
    has_password: true,
    password_login_disabled: false,
    has_passkeys: false,
    ...overrides,
  }
}

/** Make the app believe this user (or nobody) is signed in. */
export function signIn(user: User | null): void {
  useUser().currentUser.value = user
}
