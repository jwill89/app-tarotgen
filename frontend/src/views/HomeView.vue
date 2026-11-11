<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useRecentReadings, type RecentReading } from '@/composables/useRecentReadings'
import { useConfirm } from '@/composables/useConfirm'
import { useUser } from '@/composables/useUser'
import { formatDateTime } from '@/utils/datetime'
import AstrolabeMotif from '@/components/AstrolabeMotif.vue'

const router = useRouter()
const { recent, remove, clear } = useRecentReadings()
const { confirm } = useConfirm()
const { isLoggedIn } = useUser()

const readingCode = ref('')

function viewReading() {
  const rid = readingCode.value.trim()
  if (rid) {
    readingCode.value = ''
    void router.push({ name: 'reading', params: { id: rid } })
  }
}

async function removeOne(r: RecentReading) {
  const ok = await confirm({
    title: 'Remove reading',
    message: `Remove "${r.summary}" from your history? You can still open it later with its code.`,
    confirmLabel: 'Remove',
    danger: true,
  })
  if (ok) {
    remove(r.id)
  }
}

async function clearAll() {
  const ok = await confirm({
    title: 'Clear recent readings',
    message:
      'Remove all readings from your history on this device? The readings themselves are not deleted — you can still open them with their code.',
    confirmLabel: 'Clear all',
    danger: true,
  })
  if (ok) {
    clear()
  }
}
</script>

<template>
  <section class="home">
    <!-- ===================== HERO ===================== -->
    <header class="hero home-wrap">
      <div class="hero-astro" aria-hidden="true">
        <AstrolabeMotif />
      </div>
      <div class="hero-copy">
        <h1 class="hero-title">
          <span class="line1">Tarot<span class="gen">Gen</span><span class="tld">.io</span></span>
          <span class="line2">Tarot Generator</span>
        </h1>
        <p class="hero-sub">Start a new reading or look one up with the code you were provided.</p>
        <div class="hero-rule" aria-hidden="true"></div>
      </div>
    </header>

    <!-- ===================== PRIMARY DUO + QUIET ROW ===================== -->
    <section class="reading-section home-wrap" aria-label="Begin a reading">
      <h2 class="section-head">Begin a Reading</h2>

      <div class="panels">
        <!-- New Draw — PRIMARY (wider column, larger title, filled aqua button) -->
        <article class="panel panel-new">
          <span class="p-icon" aria-hidden="true">
            <svg
              viewBox="0 0 40 40"
              fill="none"
              stroke="currentColor"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="M17 12 V10.5 A2 2 0 0 1 19 8.5 H30 A2 2 0 0 1 32 10.5 V27" />
              <rect x="13" y="12" width="15" height="21" rx="2.5" />
              <circle cx="20.5" cy="22.5" r="3" />
              <line x1="20.5" y1="15.6" x2="20.5" y2="17.6" />
              <line x1="20.5" y1="27.4" x2="20.5" y2="29.4" />
              <line x1="13.6" y1="22.5" x2="15.6" y2="22.5" />
              <line x1="25.4" y1="22.5" x2="27.4" y2="22.5" />
              <line x1="15.9" y1="17.9" x2="17.3" y2="19.3" />
              <line x1="23.7" y1="25.7" x2="25.1" y2="27.1" />
              <line x1="25.1" y1="17.9" x2="23.7" y2="19.3" />
              <line x1="17.3" y1="25.7" x2="15.9" y2="27.1" />
            </svg>
          </span>
          <div class="p-label">Start here</div>
          <h3 class="p-title">New Draw</h3>
          <p class="p-desc">
            Draw a fresh spread of cards. Does not include detailed interpretation.
          </p>
          <div class="p-spacer"></div>
          <router-link class="btn-primary" :to="{ name: 'new-reading' }">
            New Draw
            <svg
              class="arrow"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <line x1="4" y1="12" x2="18" y2="12" />
              <path d="M13 7l5 5-5 5" />
            </svg>
          </router-link>
        </article>

        <!-- View a Reading — secondary, holds the reading-code input -->
        <article class="panel">
          <span class="p-icon" aria-hidden="true">
            <svg
              viewBox="0 0 40 40"
              fill="none"
              stroke="currentColor"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <circle cx="20" cy="17" r="10" />
              <path d="M13.2 26 L11 32 H29 L26.8 26" />
              <path d="M12.5 32 H27.5" />
              <path
                d="M15.8 13.4 l.8 1.9 2.1 .2 -1.6 1.4 .4 2 -1.7-1 -1.7 1 .4-2 -1.6-1.4 2.1-.2 Z"
              />
              <path d="M24.4 20.4 a2.4 2.4 0 0 1 1.9 -2.4" opacity=".55" />
            </svg>
          </span>
          <div class="p-label">Have a code?</div>
          <h3 class="p-title">View a Reading</h3>
          <p class="p-desc">Enter the code you were given to open that reading.</p>
          <div class="p-spacer"></div>
          <form class="panel-code" @submit.prevent="viewReading">
            <input
              v-model="readingCode"
              type="text"
              placeholder="Reading Code"
              aria-label="Reading Code"
            />
            <button type="submit" aria-label="View reading">
              View
              <svg
                class="arrow"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <line x1="4" y1="12" x2="18" y2="12" />
                <path d="M13 7l5 5-5 5" />
              </svg>
            </button>
          </form>
        </article>
      </div>

      <!-- Quiet row: the remaining actions, fading gold hairline dividers -->
      <div class="quiet-row">
        <router-link class="quiet-item" :to="{ name: 'custom-reading' }">
          <span class="q-icon" aria-hidden="true">
            <svg
              viewBox="0 0 40 40"
              fill="none"
              stroke="currentColor"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <g transform="rotate(-12 20 11)">
                <rect x="14.5" y="4" width="11" height="15" rx="2" />
                <path
                  d="M20 8.2 l.9 2 2.2 .2 -1.6 1.5 .4 2.1 -1.9-1.1 -1.9 1.1 .4-2.1 -1.6-1.5 2.2-.2 Z"
                />
              </g>
              <path d="M11 33 a9.5 9.5 0 0 1 18.4 -1.4" />
              <line x1="13.6" y1="28.6" x2="14.6" y2="24.6" />
              <line x1="17.7" y1="27" x2="18.3" y2="22.6" />
              <line x1="21.7" y1="26.8" x2="22" y2="22.4" />
              <line x1="25.5" y1="27.6" x2="25.3" y2="24" />
            </svg>
          </span>
          <h3>Recreate Draw</h3>
          <p>Recreate a real spread by placing specific cards in the positions you want.</p>
        </router-link>

        <router-link class="quiet-item" :to="{ name: 'submit-spread' }">
          <span class="q-icon" aria-hidden="true">
            <svg
              viewBox="0 0 40 40"
              fill="none"
              stroke="currentColor"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="M9 14 L19 8.5 L31 13 L27 27 L15 29 Z" opacity=".5" />
              <path d="M19 8.5 L15 29" opacity=".3" />
              <circle cx="9" cy="14" r="1.7" fill="currentColor" stroke="none" />
              <circle cx="19" cy="8.5" r="1.7" fill="currentColor" stroke="none" />
              <circle cx="31" cy="13" r="1.7" fill="currentColor" stroke="none" />
              <circle cx="27" cy="27" r="1.7" fill="currentColor" stroke="none" />
              <circle cx="15" cy="29" r="1.7" fill="currentColor" stroke="none" />
            </svg>
          </span>
          <h3>Create a Spread</h3>
          <p>Design a custom spread to use or share with others.</p>
        </router-link>

        <router-link v-if="isLoggedIn" class="quiet-item" :to="{ name: 'submit-deck' }">
          <span class="q-icon" aria-hidden="true">
            <svg
              viewBox="0 0 40 40"
              fill="none"
              stroke="currentColor"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <rect x="9" y="17" width="13" height="18" rx="2" transform="rotate(-8 15.5 26)" />
              <rect x="15" y="14" width="13" height="18" rx="2" transform="rotate(7 21.5 23)" />
              <path d="M28.5 7 a4.6 4.6 0 1 0 3.6 7.4 a5.6 5.6 0 0 1 -3.6 -7.4 Z" />
            </svg>
          </span>
          <h3>Submit a Deck</h3>
          <p>Suggest a tarot deck to be added to the site.</p>
        </router-link>

        <router-link class="quiet-item" :to="{ name: 'ffxiv-plugin' }">
          <span class="q-icon" aria-hidden="true">
            <svg
              viewBox="0 0 40 40"
              fill="none"
              stroke="currentColor"
              stroke-width="1.4"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path
                d="M14 15 H26 A7 7 0 0 1 32 25 L30.6 29 A3 3 0 0 1 25.4 29.4 L23.6 26.6 H16.4 L14.6 29.4 A3 3 0 0 1 9.4 29 L8 25 A7 7 0 0 1 14 15 Z"
              />
              <line x1="13" y1="21.4" x2="18" y2="21.4" />
              <line x1="15.5" y1="18.9" x2="15.5" y2="23.9" />
              <circle cx="24.4" cy="19.6" r="1.05" fill="currentColor" stroke="none" />
              <circle cx="27.4" cy="22.6" r="1.05" fill="currentColor" stroke="none" />
              <circle cx="21.4" cy="22.6" r="1.05" fill="currentColor" stroke="none" />
              <path d="M24.4 19.6 L27.4 22.6 M24.4 19.6 L21.4 22.6" opacity=".4" />
            </svg>
          </span>
          <h3>FFXIV Plugin</h3>
          <p>Draw and view readings in-game. Install the free Dalamud plugin.</p>
        </router-link>
      </div>
    </section>

    <div class="rule home-wrap" aria-hidden="true"><span class="node"></span></div>

    <!-- ===================== RECENT READINGS ===================== -->
    <section
      v-if="recent.length > 0"
      class="recent-section home-wrap"
      aria-label="Your recent readings"
    >
      <div class="recent-head">
        <h2>Your Recent Readings</h2>
        <span class="hint"
          >Saved only on this device. Anyone with a reading's code can view it.</span
        >
        <button class="recent-clear" type="button" @click="clearAll">Clear all</button>
      </div>

      <div class="table-wrap">
        <table class="recent-table">
          <thead>
            <tr>
              <th scope="col">Reading</th>
              <th scope="col">Deck</th>
              <th scope="col">Date</th>
              <th scope="col" class="col-actions"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recent" :key="r.id">
              <td>
                <span class="reading-name">
                  <span class="r-icon" aria-hidden="true">
                    <svg
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.4"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <circle cx="12" cy="12" r="7.6" />
                      <line x1="12" y1="3.6" x2="12" y2="20.4" />
                      <line x1="3.6" y1="12" x2="20.4" y2="12" />
                    </svg>
                  </span>
                  {{ r.summary }}
                </span>
              </td>
              <td class="c-deck">{{ r.deckName }}</td>
              <td class="c-date">{{ formatDateTime(r.at) }}</td>
              <td class="c-actions">
                <router-link
                  class="t-open"
                  :to="{ name: 'reading', params: { id: r.id } }"
                  :aria-label="`Open ${r.summary}`"
                >
                  Open
                </router-link>
                <button
                  class="t-remove"
                  type="button"
                  :aria-label="`Remove ${r.summary} from recent readings`"
                  @click="removeOne(r)"
                >
                  &times;
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<style scoped>
/* Short local aliases for the global `--myst-*` tokens, so the rules below read cleanly. */
.home {
  --gold: var(--myst-gold);
  --gold-bright: var(--myst-gold-bright);
  --aqua: var(--myst-aqua);
  --aqua-soft: var(--myst-aqua-soft);
  --aqua-dim: var(--myst-aqua-dim);
  --aqua-glow: var(--myst-aqua-glow);
  --text: var(--myst-text);
  --text-muted: var(--myst-text-muted);
  --text-soft: var(--myst-text-soft);
  --silver: var(--myst-silver);
  --chrome: var(--myst-chrome);
  --surface: var(--myst-surface);
  --surface-2: var(--myst-surface-2);
  --hair: var(--myst-border);
  --hair-gold: var(--myst-hair-gold);
  --bg: var(--myst-bg);
  --font-display: var(--myst-heading-font);
  --font-ui: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;

  display: block;
  padding-bottom: 3.5rem;
}

.home-wrap {
  max-width: 1140px;
  margin: 0 auto;
  padding: 0 22px;
  width: 100%;
}

/* ============ HERO (compact, asymmetric) ============ */
.hero {
  position: relative;
  padding-top: 2rem;
  padding-bottom: 40px;
  overflow: visible;
}

.hero-astro {
  position: absolute;
  top: 1.5rem;
  right: -30px;
  width: min(420px, 48%);
  color: var(--gold);
  opacity: 0.5;
  pointer-events: none;
  z-index: 0;
}

.hero-astro :deep(svg) {
  filter: drop-shadow(0 0 22px color-mix(in srgb, var(--myst-gold) 12%, transparent));
}

.hero-copy {
  position: relative;
  z-index: 1;
  max-width: 600px;
}

.hero-title {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: clamp(34px, 6.2vw, 56px);
  line-height: 1.05;
  letter-spacing: 0.02em;
  margin: 0 0 14px;
  color: var(--text);
}

.hero-title .line1,
.hero-title .line2 {
  display: block;
}

.hero-title .line1 {
  font-size: 1em;
  letter-spacing: 0.02em;
  color: var(--text);
}

.hero-title .line1 .gen {
  color: var(--gold);
}

.hero-title .line1 .tld {
  font-size: 0.6em;
  letter-spacing: 0.04em;
  color: var(--text-muted);
}

.hero-title .line2 {
  font-size: 0.42em;
  letter-spacing: 0.16em;
  color: var(--text-muted);
  margin-top: 8px;
  font-weight: 600;
}

.hero-sub {
  font-size: clamp(15px, 2.1vw, 18px);
  color: var(--text-muted);
  max-width: 46ch;
  margin: 0;
}

/* clearly-visible gold rule: solid at the left edge, fades before the astrolabe */
.hero-rule {
  margin-top: 26px;
  height: 2px;
  width: min(540px, 100%);
  border-radius: 2px;
  background: linear-gradient(
    90deg,
    var(--gold) 0%,
    var(--gold) 34%,
    color-mix(in srgb, var(--myst-gold) 55%, transparent) 64%,
    transparent 100%
  );
}

/* ============ section scaffolding ============ */
.reading-section {
  padding-top: 26px;
}

.section-head {
  font-family: var(--font-display);
  letter-spacing: 0.05em;
  font-weight: 600;
  font-size: 1.5rem;
  margin: 0 0 18px;
  color: var(--text);
}

.rule {
  position: relative;
  height: 1px;
  margin: 44px auto;
  max-width: 760px;
  background: linear-gradient(
    90deg,
    transparent,
    var(--hair-gold) 16%,
    var(--hair-gold) 84%,
    transparent
  );
}

.rule .node {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 7px;
  height: 7px;
  transform: translate(-50%, -50%) rotate(45deg);
  border: 1px solid var(--gold);
  background: var(--bg);
}

/* ============ DUO PANELS (New Draw = primary by SIZE) ============ */
.panels {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 20px;
  align-items: stretch;
}

.panel {
  position: relative;
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, var(--surface), var(--chrome));
  border: 1px solid var(--hair-gold);
  border-radius: 18px;
  padding: 30px 30px 28px;
  min-height: 236px;
  height: 100%; /* fill the grid row so both panels are exactly equal height */
  overflow: hidden;
  transition:
    transform 0.3s ease,
    border-color 0.3s ease,
    box-shadow 0.3s ease;
}

.panel:hover {
  transform: translateY(-4px);
  border-color: color-mix(in srgb, var(--myst-gold) 60%, transparent);
  box-shadow: 0 18px 46px rgb(0 0 0 / 50%);
}

.panel:focus-within {
  border-color: color-mix(in srgb, var(--myst-gold) 60%, transparent);
}

.panel .p-icon {
  width: 42px;
  height: 42px;
  color: var(--gold);
  margin-bottom: 18px;
  flex: none;
}

.panel .p-icon svg {
  width: 100%;
  height: 100%;
  display: block;
}

.p-label {
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--silver);
  margin-bottom: 9px;
}

.p-title {
  font-family: var(--font-display);
  letter-spacing: 0.05em;
  font-weight: 600;
  font-size: 1.7rem;
  margin: 0 0 11px;
  color: var(--text);
}

.p-desc {
  color: var(--text-muted);
  font-size: 0.98rem;
  margin: 0 0 22px;
  max-width: 34ch;
}

.p-spacer {
  flex: 1;
}

/* New Draw = PRIMARY: wider column, bigger title, filled aqua button */
.panel-new {
  border-color: var(--aqua-dim);
  background:
    radial-gradient(120% 90% at 82% 0%, var(--aqua-soft), transparent 55%),
    linear-gradient(180deg, color-mix(in srgb, var(--myst-aqua) 5%, transparent), var(--chrome));
  box-shadow:
    inset 0 0 0 1px color-mix(in srgb, var(--myst-aqua) 6%, transparent),
    0 16px 42px rgb(0 0 0 / 40%);
  padding: 34px 34px 32px;
}

.panel-new .p-icon {
  color: var(--aqua);
  width: 46px;
  height: 46px;
}

.panel-new .p-title {
  font-size: 2.15rem;
  margin-bottom: 12px;
}

.panel-new .p-desc {
  font-size: 1.02rem;
  max-width: 40ch;
}

.panel-new:hover {
  transform: translateY(-5px);
  border-color: var(--aqua);
  box-shadow:
    0 0 42px var(--aqua-glow),
    0 22px 55px rgb(0 0 0 / 55%);
}

.btn-primary {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  background: var(--aqua);
  color: var(--myst-on-aqua);
  border: 1px solid transparent;
  font-family: var(--font-ui);
  font-size: 1.02rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 14px 26px;
  border-radius: 11px;
  text-decoration: none;
  box-shadow:
    0 0 0 1px color-mix(in srgb, var(--myst-aqua) 35%, transparent),
    0 8px 26px -12px color-mix(in srgb, var(--myst-aqua) 70%, transparent);
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease,
    background 0.25s ease;
}

.btn-primary:hover {
  transform: translateY(-2px);
  background: var(--myst-aqua-bright);
  box-shadow:
    0 0 0 1px color-mix(in srgb, var(--myst-aqua) 50%, transparent),
    0 14px 34px -12px color-mix(in srgb, var(--myst-aqua) 85%, transparent);
}

.btn-primary:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-aqua) 40%, transparent);
}

.btn-primary .arrow {
  width: 18px;
  height: 18px;
}

/* View a Reading = secondary: outlined code affordance */
.panel-code {
  display: flex;
  align-items: stretch;
  background: var(--surface-2);
  border: 1px solid var(--hair-gold);
  border-radius: 12px;
  overflow: hidden;
  max-width: 100%;
  transition:
    border-color 0.25s ease,
    box-shadow 0.25s ease;
}

.panel-code:focus-within {
  border-color: color-mix(in srgb, var(--myst-gold) 70%, transparent);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 12%, transparent);
}

.panel-code input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: transparent;
  color: var(--text);
  font-family: var(--font-ui);
  font-size: 0.98rem;
  padding: 13px 16px;
}

.panel-code input::placeholder {
  color: var(--text-soft);
}

.panel-code input:focus {
  outline: none;
}

.panel-code button {
  border: 0;
  border-left: 1px solid var(--hair-gold);
  background: transparent;
  color: var(--gold-bright);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-ui);
  font-size: 0.92rem;
  letter-spacing: 0.02em;
  padding: 0 18px;
  transition: background 0.25s ease;
}

.panel-code button:hover {
  background: color-mix(in srgb, var(--myst-gold) 12%, transparent);
}

.panel-code button:focus-visible {
  outline: none;
  box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--myst-gold) 40%, transparent);
}

.panel-code .arrow {
  width: 18px;
  height: 18px;
}

/* ============ QUIET ROW (fading gold hairlines) ============ */
.quiet-row {
  margin-top: 20px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
}

.quiet-item {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 24px 22px;
  border-radius: 12px;
  text-decoration: none;
  transition:
    background 0.25s ease,
    transform 0.25s ease;
}

.quiet-item::before {
  content: '';
  position: absolute;
  left: 0;
  top: 16%;
  bottom: 16%;
  width: 1px;
  background: linear-gradient(
    180deg,
    transparent,
    var(--hair-gold) 22%,
    var(--hair-gold) 78%,
    transparent
  );
}

.quiet-item:first-child::before {
  display: none;
}

.quiet-item:hover {
  background: color-mix(in srgb, var(--myst-surface-3) 45%, transparent);
  transform: translateY(-3px);
}

.quiet-item:focus-visible {
  outline: none;
  background: color-mix(in srgb, var(--myst-surface-3) 55%, transparent);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--myst-gold) 30%, transparent);
}

.quiet-item .q-icon {
  width: 32px;
  height: 32px;
  color: var(--gold);
  margin-bottom: 15px;
  transition:
    transform 0.3s ease,
    color 0.3s ease;
}

.quiet-item .q-icon svg {
  width: 100%;
  height: 100%;
  display: block;
}

.quiet-item:hover .q-icon {
  transform: translateY(-2px);
  color: var(--gold-bright);
}

.quiet-item h3 {
  font-family: var(--font-display);
  letter-spacing: 0.045em;
  font-weight: 600;
  font-size: 1.15rem;
  margin: 0 0 7px;
  color: var(--silver);
  text-decoration: underline;
  text-decoration-color: var(--hair-gold);
  text-decoration-thickness: 1px;
  text-underline-offset: 3px;
  transition:
    color 0.3s ease,
    text-decoration-color 0.3s ease;
}

.quiet-item:hover h3 {
  color: var(--text);
  text-decoration-color: var(--gold-bright);
}

.quiet-item p {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.85rem;
  line-height: 1.5;
}

/* ============ RECENT READINGS (compact data table) ============ */
.recent-section {
  padding-top: 8px;
}

.recent-head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px 16px;
  margin-bottom: 16px;
}

.recent-head h2 {
  font-family: var(--font-display);
  letter-spacing: 0.05em;
  font-weight: 600;
  font-size: 1.5rem;
  margin: 0;
  color: var(--text);
}

.recent-head .hint {
  color: var(--text-soft);
  font-size: 0.82rem;
  max-width: 46ch;
}

.recent-head .recent-clear {
  margin-left: auto;
  background: transparent;
  border: 1px solid var(--hair);
  color: var(--text-muted);
  font-family: var(--font-ui);
  font-size: 0.8rem;
  letter-spacing: 0.02em;
  padding: 6px 12px;
  border-radius: 8px;
  cursor: pointer;
  transition:
    color 0.2s ease,
    border-color 0.2s ease,
    background 0.2s ease;
}

.recent-head .recent-clear:hover {
  color: var(--text);
  border-color: var(--hair-gold);
  background: color-mix(in srgb, var(--myst-gold) 8%, transparent);
}

.recent-head .recent-clear:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 20%, transparent);
}

.table-wrap {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border: 1px solid var(--hair);
  border-radius: 14px;
  background: var(--surface);
}

.recent-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
}

.recent-table thead th {
  text-align: left;
  font-family: var(--font-display);
  letter-spacing: 0.12em;
  font-weight: 600;
  font-size: 0.82rem;
  color: var(--silver);
  padding: 14px 18px 12px;
  border-bottom: 1px solid var(--hair-gold);
  white-space: nowrap;
  background: var(--surface-2);
}

.recent-table th.col-actions {
  text-align: right;
}

.recent-table tbody td {
  padding: 14px 18px;
  border-bottom: 1px solid var(--hair);
  vertical-align: middle;
  color: var(--text-muted);
}

.recent-table tbody tr {
  transition: background 0.2s ease;
}

.recent-table tbody tr:hover {
  background: var(--surface-2);
}

.recent-table tbody tr:last-child td {
  border-bottom: 0;
}

.reading-name {
  display: inline-flex;
  align-items: center;
  gap: 11px;
  color: var(--text);
  font-family: var(--font-display);
  letter-spacing: 0.02em;
  font-size: 1.02rem;
}

.reading-name .r-icon {
  width: 22px;
  height: 22px;
  color: var(--gold);
  flex: none;
}

.reading-name .r-icon svg {
  width: 100%;
  height: 100%;
  display: block;
}

.c-deck {
  color: var(--text-muted);
  white-space: nowrap;
}

.c-date {
  color: var(--text-soft);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.c-actions {
  text-align: right;
  white-space: nowrap;
}

.t-open {
  display: inline-block;
  color: var(--gold-bright);
  font-family: var(--font-ui);
  font-size: 0.85rem;
  text-decoration: underline;
  text-decoration-color: color-mix(in srgb, var(--myst-gold) 55%, transparent);
  text-underline-offset: 3px;
  padding: 5px 8px;
  border-radius: 7px;
  transition:
    color 0.2s ease,
    background 0.2s ease,
    text-decoration-color 0.2s ease;
}

.t-open:hover {
  text-decoration-color: var(--gold-bright);
  background: color-mix(in srgb, var(--myst-gold) 10%, transparent);
}

.t-remove {
  width: 28px;
  height: 28px;
  margin-left: 6px;
  border-radius: 7px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--text-soft);
  cursor: pointer;
  font-size: 1.1rem;
  line-height: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
  transition:
    color 0.2s ease,
    border-color 0.2s ease,
    background 0.2s ease;
}

.t-remove:hover {
  color: var(--text);
  border-color: var(--hair-gold);
  background: color-mix(in srgb, var(--myst-gold) 10%, transparent);
}

.t-remove:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 20%, transparent);
}

/* ============ RESPONSIVE ============ */
@media (width <= 960px) {
  .hero-astro {
    opacity: 0.42;
    width: min(340px, 44%);
  }
}

@media (width <= 720px) {
  .panels {
    grid-template-columns: 1fr;
  }

  .panel-new .p-title {
    font-size: 1.95rem;
  }
}

@media (width <= 560px) {
  .quiet-row {
    grid-template-columns: 1fr;
  }

  .quiet-item::before {
    display: none;
  }

  .quiet-item::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    height: 1px;
    display: block;
    background: linear-gradient(
      90deg,
      transparent,
      var(--hair-gold) 18%,
      var(--hair-gold) 82%,
      transparent
    );
  }

  .quiet-item:first-child::after {
    display: none;
  }

  .hero-astro {
    position: absolute;
    top: 92px;
    right: auto;
    left: 50%;
    transform: translateX(-50%);
    width: min(400px, 84%);
    opacity: 0.13;
  }
}

@media (prefers-reduced-motion: reduce) {
  .panel:hover,
  .quiet-item:hover {
    transform: none;
  }
}
</style>
