<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, useTemplateRef } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import type { IconName } from '@fortawesome/fontawesome-svg-core'
import { useUser } from '@/composables/useUser'
import {
  DropdownMenuRoot,
  DropdownMenuTrigger,
  DropdownMenuPortal,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuLabel,
  DialogRoot,
  DialogTrigger,
  DialogPortal,
  DialogOverlay,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogClose,
  TooltipProvider,
} from 'reka-ui'
import ToastContainer from '@/components/ToastContainer.vue'
import StarField from '@/components/StarField.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CookieBanner from '@/components/CookieBanner.vue'

const router = useRouter()
const { currentUser, isLoggedIn, logout: userLogout } = useUser()

const burgerOpen = ref(false)
const searchReadingId = ref('')
const codeDialogOpen = ref(false)
const currentYear = computed(() => new Date().getFullYear())

// See the note in the previous revision: /assets is proxied to the PHP host, so
// the brand logo is referenced as a runtime URL string, not a bundled import.
const brandLogo = '/assets/favicon.png'

// Primary nav links (also used by the mobile menu). Creating a spread starts
// from the Spreads library (and Home), which keeps the bar to five links: a
// sixth wrapped them between the burger breakpoint and ~1100px.
const navLinks = [
  { label: 'Home', to: { name: 'home' } },
  { label: 'New Draw', to: { name: 'new-reading' } },
  { label: 'Spreads', to: { name: 'spread-library' } },
  { label: 'Recreate Draw', to: { name: 'custom-reading' } },
  { label: 'FFXIV Plugin', to: { name: 'ffxiv-plugin' } },
]

/** A menu link with its icon, which fontawesome.ts must register. */
interface MenuItem {
  label: string
  icon: IconName
  to: RouteLocationRaw
}

// Account dropdown items (shared by the Reka menu + the mobile menu).
const accountItems: MenuItem[] = [
  { label: 'Dashboard', icon: 'gauge-high', to: { name: 'dashboard' } },
  { label: 'My Readings', icon: 'scroll', to: { name: 'account-readings' } },
  { label: 'Daily Card', icon: 'calendar-day', to: { name: 'daily-card' } },
  { label: 'My Spreads', icon: 'table-cells', to: { name: 'account-spreads' } },
  { label: 'Account Settings', icon: 'gear', to: { name: 'account-settings' } },
]

const adminItems: MenuItem[] = [
  { label: 'Dashboard', icon: 'gauge-high', to: { name: 'admin-dashboard' } },
  { label: 'Decks', icon: 'cards-blank', to: { name: 'admin-decks' } },
  { label: 'Special Cards', icon: 'sparkles', to: { name: 'admin-special-cards' } },
  { label: 'Spreads', icon: 'table-cells', to: { name: 'admin-spreads' } },
  { label: 'Readings', icon: 'scroll', to: { name: 'admin-readings' } },
  { label: 'Users', icon: 'users', to: { name: 'admin-users' } },
  { label: 'Contacts', icon: 'envelope', to: { name: 'admin-contacts' } },
  { label: 'Errored Cards', icon: 'triangle-exclamation', to: { name: 'admin-card-reports' } },
]

function closeMenu() {
  burgerOpen.value = false
}

// Open the reading-code dialog (from either the desktop nav control or the
// mobile menu; the dialog itself is controlled via codeDialogOpen).
function openCodeDialog() {
  burgerOpen.value = false
  codeDialogOpen.value = true
}

function viewReading() {
  const rid = searchReadingId.value.trim()
  burgerOpen.value = false
  codeDialogOpen.value = false
  if (rid) {
    searchReadingId.value = ''
    void router.push({ name: 'reading', params: { id: rid } })
  } else {
    void router.push({ name: 'new-reading' })
  }
}

async function doUserLogout() {
  burgerOpen.value = false
  await userLogout()
  void router.push({ name: 'home' })
}

// The skip link moves focus itself rather than following its #main-content href,
// which would put a hash in the URL for the router to act on.
const mainContent = useTemplateRef<HTMLElement>('mainContent')
function skipToContent() {
  mainContent.value?.focus()
}
</script>

<template>
  <TooltipProvider :delay-duration="300">
    <div class="app-wrapper">
      <a class="skip-link" href="#main-content" @click.prevent="skipToContent">Skip to content</a>
      <StarField />

      <nav class="site-nav" aria-label="main navigation">
        <div class="nav-inner">
          <router-link class="brand" :to="{ name: 'home' }" aria-label="TarotGen.io home">
            <span class="glyph"><img :src="brandLogo" alt="" class="brand-logo" /></span>
            <span class="word">Tarot<span class="gen">Gen</span><span class="tld">.io</span></span>
          </router-link>

          <div class="nav-links">
            <template v-for="(l, i) in navLinks" :key="l.label">
              <router-link :to="l.to" exact-active-class="active" @click="closeMenu">{{
                l.label
              }}</router-link>
              <span v-if="i < navLinks.length - 1" class="nav-sep" aria-hidden="true">·</span>
            </template>
          </div>

          <div class="nav-right">
            <DialogRoot v-model:open="codeDialogOpen">
              <DialogTrigger class="btn btn-quiet nav-code-btn">
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
                /></span>
                <span class="nav-code-label">View a Reading</span>
              </DialogTrigger>
              <DialogPortal>
                <DialogOverlay class="myst-dialog-overlay" />
                <DialogContent class="myst-dialog">
                  <DialogTitle class="myst-dialog-title">View a Reading</DialogTitle>
                  <DialogDescription class="myst-dialog-desc">
                    Enter the code you were given to open that reading.
                  </DialogDescription>
                  <form class="code-dialog-form" @submit.prevent="viewReading">
                    <input
                      v-model="searchReadingId"
                      class="code-dialog-input"
                      type="text"
                      placeholder="Reading Code"
                      aria-label="Reading Code"
                    />
                    <button class="btn-view" type="submit">
                      View
                      <span class="icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-right']"
                      /></span>
                    </button>
                  </form>
                  <DialogClose class="myst-dialog-close" aria-label="Close">
                    <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
                  </DialogClose>
                </DialogContent>
              </DialogPortal>
            </DialogRoot>

            <router-link v-if="!isLoggedIn" class="btn btn-login" :to="{ name: 'login' }">
              Log In
            </router-link>

            <DropdownMenuRoot v-else :modal="false">
              <DropdownMenuTrigger class="btn account-trigger">
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-user']"
                /></span>
                <span class="acct-name">{{ currentUser?.display_name }}</span>
                <span class="chev"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']"
                /></span>
              </DropdownMenuTrigger>
              <DropdownMenuPortal>
                <DropdownMenuContent class="myst-menu" align="end" :side-offset="8">
                  <DropdownMenuItem v-for="item in accountItems" :key="item.label" as-child>
                    <router-link class="myst-menu-item" :to="item.to">
                      <span class="mi-icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas[item.icon]"
                      /></span>
                      <span>{{ item.label }}</span>
                    </router-link>
                  </DropdownMenuItem>

                  <template v-if="currentUser?.is_admin">
                    <DropdownMenuSeparator class="myst-menu-sep" />
                    <DropdownMenuLabel class="myst-menu-label">
                      <span class="mi-icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock-keyhole']"
                      /></span>
                      <span>Admin</span>
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                      v-for="item in adminItems"
                      :key="'admin-' + item.label"
                      as-child
                    >
                      <router-link class="myst-menu-item" :to="item.to">
                        <span class="mi-icon"
                          ><FontAwesomeIcon :icon="byPrefixAndName.fas[item.icon]"
                        /></span>
                        <span>{{ item.label }}</span>
                      </router-link>
                    </DropdownMenuItem>
                  </template>

                  <DropdownMenuSeparator class="myst-menu-sep" />
                  <DropdownMenuItem class="myst-menu-item" @select="doUserLogout">
                    <span class="mi-icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['right-from-bracket']"
                    /></span>
                    <span>Log Out</span>
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenuPortal>
            </DropdownMenuRoot>

            <button
              class="hamburger"
              :class="{ 'is-open': burgerOpen }"
              type="button"
              aria-label="Toggle navigation menu"
              :aria-expanded="burgerOpen ? 'true' : 'false'"
              @click="burgerOpen = !burgerOpen"
            >
              <span></span><span></span><span></span>
            </button>
          </div>
        </div>

        <div class="mobile-menu" :class="{ 'is-open': burgerOpen }">
          <div class="mobile-menu-inner">
            <router-link
              v-for="l in navLinks"
              :key="'m-' + l.label"
              :to="l.to"
              exact-active-class="active"
              @click="closeMenu"
            >
              {{ l.label }}
            </router-link>

            <button class="btn btn-quiet mm-code-btn" type="button" @click="openCodeDialog">
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
              /></span>
              <span>View a Reading</span>
            </button>

            <template v-if="!isLoggedIn">
              <router-link
                class="btn btn-login mm-login"
                :to="{ name: 'login' }"
                @click="closeMenu"
              >
                Log In
              </router-link>
            </template>
            <template v-else>
              <div class="mm-divider" aria-hidden="true"></div>
              <router-link
                v-for="item in accountItems"
                :key="'m-' + item.label"
                :to="item.to"
                @click="closeMenu"
              >
                {{ item.label }}
              </router-link>
              <template v-if="currentUser?.is_admin">
                <router-link
                  v-for="item in adminItems"
                  :key="'m-admin-' + item.label"
                  :to="item.to"
                  @click="closeMenu"
                >
                  Admin · {{ item.label }}
                </router-link>
              </template>
              <a
                class="mm-logout"
                role="button"
                tabindex="0"
                @click="doUserLogout"
                @keyup.enter="doUserLogout"
                @keydown.space.prevent="doUserLogout"
              >
                Log Out
              </a>
            </template>
          </div>
        </div>
      </nav>

      <main id="main-content" ref="mainContent" tabindex="-1">
        <router-view />
      </main>

      <ConfirmDialog />
      <ToastContainer />
      <CookieBanner />

      <footer class="footer mt-auto">
        <div class="content has-text-centered">
          <p>
            Copyright &copy; {{ currentYear }}. Coded by
            <a href="https://www.mathdad.me"><strong>MathDad</strong></a
            >.<br />
            This site is a tool for readers and those learning; it is not meant to replace the need
            for a reader. Please use responsibly!<br />
            <router-link :to="{ name: 'privacy-policy' }">Privacy Policy</router-link> |
            <router-link :to="{ name: 'terms-of-service' }">Terms of Service</router-link> |
            <router-link :to="{ name: 'contact' }">Contact Us</router-link>
          </p>
        </div>
      </footer>
    </div>
  </TooltipProvider>
</template>

<style scoped>
/* Local aliases → global mystical tokens (keeps the nav CSS readable). */
.site-nav,
.mobile-menu {
  --gold: var(--myst-gold);
  --gold-bright: var(--myst-gold-bright);
  --aqua: var(--myst-aqua);
  --aqua-glow: var(--myst-aqua-glow);
  --text: var(--myst-text);
  --text-muted: var(--myst-text-muted);
  --text-soft: var(--myst-text-soft);
  --chrome: var(--myst-chrome);
  --surface: var(--myst-surface);
  --hair: var(--myst-border);
  --hair-gold: var(--myst-hair-gold);
  --font-display: var(--myst-heading-font);
  --font-ui: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
}

/* Off-screen until a keyboard user tabs to it: the first stop on every page. */
.skip-link {
  position: fixed;
  top: 12px;
  left: 12px;
  z-index: 60;
  padding: 8px 15px;
  border: 1px solid var(--myst-gold);
  border-radius: 9px;
  background: var(--myst-chrome);
  color: var(--myst-gold-bright);
  font-size: 14px;
  text-decoration: none;
  transform: translateY(calc(-100% - 24px));
}

.skip-link:focus-visible {
  transform: none;
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 25%, transparent);
}

/* ============ NAV (solid chrome) ============ */
.site-nav {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 50;
  background: var(--chrome);
  border-bottom: 1px solid var(--hair-gold);
}

.nav-inner {
  max-width: 1140px;
  margin: 0 auto;
  height: 64px;
  padding: 0 22px;
  display: flex;
  align-items: center;
  gap: 20px;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: none;
  text-decoration: none;
}

.brand .glyph {
  display: inline-flex;
  align-items: center;
}

.brand-logo {
  height: 1.6rem;
  width: auto;
  display: block;
}

.word {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 19px;
  letter-spacing: 0.05em;
  color: var(--text);
}

.word .gen {
  color: var(--gold);
}

.word .tld {
  font-size: 0.7em;
  letter-spacing: 0.04em;
  color: var(--text-muted);
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 2px;
  margin-left: 8px;
}

.nav-links a {
  color: var(--text-muted);
  font-size: 14px;
  padding: 7px 11px;
  border-radius: 9px;
  position: relative;
  text-decoration: none;
  transition:
    color 0.25s ease,
    background 0.25s ease;
}

/* resting affordance: a persistent low-opacity underline under every nav item */
.nav-links a::after {
  content: '';
  position: absolute;
  left: 11px;
  right: 11px;
  bottom: -1px;
  height: 2px;
  border-radius: 2px;
  background: rgb(255 255 255 / 16%);
  transition:
    background 0.25s ease,
    box-shadow 0.25s ease;
}

.nav-links a:hover {
  color: var(--text);
  background: rgb(255 255 255 / 5%);
}

.nav-links a:hover::after {
  background: rgb(255 255 255 / 40%);
}

.nav-links a.active {
  color: var(--aqua);
}

.nav-links a.active::after {
  background: var(--aqua);
  box-shadow: 0 0 10px var(--aqua-glow);
}

.nav-sep {
  color: var(--text-soft);
  font-size: 13px;
  user-select: none;
}

.nav-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10px;
}

.code-dialog-input {
  background: var(--surface);
  border: 1px solid var(--hair);
  color: var(--text);
  border-radius: 9px;
  font-family: var(--font-ui);
  font-size: 14px;
  padding: 8px 12px;
  min-width: 0;
  transition:
    border-color 0.25s ease,
    box-shadow 0.25s ease,
    background 0.25s ease;
}

.code-dialog-input::placeholder {
  color: var(--text-soft);
}

.code-dialog-input:focus {
  outline: none;
  border-color: var(--hair-gold);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 12%, transparent);
}

.nav-code-label {
  white-space: nowrap;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-ui);
  font-size: 14px;
  cursor: pointer;
  border-radius: 9px;
  padding: 8px 15px;
  background: transparent;
  color: var(--text);
  border: 1px solid var(--hair-gold);
  text-decoration: none;
  transition:
    border-color 0.25s ease,
    color 0.25s ease,
    background 0.25s ease,
    box-shadow 0.25s ease;
}

.btn:hover {
  border-color: var(--gold);
  color: var(--gold-bright);
  background: color-mix(in srgb, var(--myst-gold) 6%, transparent);
}

.btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 20%, transparent);
}

.btn-quiet {
  border-color: var(--hair);
  color: var(--text-muted);
}

.btn-quiet:hover {
  border-color: var(--hair-gold);
  color: var(--text);
  background: rgb(255 255 255 / 3%);
}

.account-trigger .acct-name {
  max-width: 12ch;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account-trigger .chev {
  font-size: 0.7em;
  transition: transform 0.25s ease;
}

.account-trigger[data-state='open'] .chev {
  transform: rotate(180deg);
}

/* hamburger */
.hamburger {
  display: none;
  width: 42px;
  height: 40px;
  border: 1px solid var(--hair-gold);
  border-radius: 9px;
  cursor: pointer;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;
  background: transparent;
  transition:
    border-color 0.25s ease,
    background 0.25s ease;
}

.hamburger span {
  display: block;
  width: 18px;
  height: 1.6px;
  background: var(--gold);
  border-radius: 2px;
  transition:
    transform 0.3s ease,
    opacity 0.25s ease;
}

.hamburger:hover {
  border-color: var(--gold);
  background: color-mix(in srgb, var(--myst-gold) 6%, transparent);
}

.hamburger:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 25%, transparent);
}

.hamburger.is-open span:nth-child(1) {
  transform: translateY(6.6px) rotate(45deg);
}

.hamburger.is-open span:nth-child(2) {
  opacity: 0;
}

.hamburger.is-open span:nth-child(3) {
  transform: translateY(-6.6px) rotate(-45deg);
}

/* mobile menu (solid chrome) */
.mobile-menu {
  overflow: hidden;
  max-height: 0;
  border-top: 1px solid transparent;
  background: var(--chrome);
  transition:
    max-height 0.38s ease,
    border-color 0.38s ease;
}

.mobile-menu.is-open {
  max-height: 620px;
  border-top-color: var(--hair);
  overflow-y: auto;
}

.mobile-menu-inner {
  max-width: 1140px;
  margin: 0 auto;
  padding: 12px 22px 20px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.mobile-menu a {
  color: var(--text-muted);
  font-size: 15px;
  padding: 11px 6px;
  border-radius: 8px;
  text-decoration: none;
  transition:
    color 0.2s ease,
    background 0.2s ease;
}

.mobile-menu a:hover {
  color: var(--text);
  background: rgb(255 255 255 / 3%);
}

.mobile-menu a.active {
  color: var(--aqua);
}

.mm-code-btn {
  margin-top: 10px;
  justify-content: center;
}

.mm-login {
  margin-top: 10px;
  justify-content: center;
}

.mm-divider {
  height: 1px;
  background: var(--hair);
  margin: 10px 0 6px;
}

.mm-logout {
  color: var(--gold-bright) !important;
  cursor: pointer;
  margin-top: 4px;
}

/* Show the burger + hide the inline links/code on narrow screens. */
@media (width <= 860px) {
  .nav-links,
  .nav-code-btn,
  .btn-login {
    display: none;
  }

  .account-trigger {
    display: none;
  }

  .hamburger {
    display: flex;
  }
}

@media (width <= 400px) {
  .nav-inner,
  .mobile-menu-inner {
    padding-left: 16px;
    padding-right: 16px;
  }

  .word {
    font-size: 17px;
  }
}
</style>
