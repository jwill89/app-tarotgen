<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { useToasts } from '@/composables/useToasts'
import PageHeader from '@/components/PageHeader.vue'

// The self-hosted custom Dalamud repository users paste into XIVLauncher.
// (Served by the backend at /plugin/, not by this SPA — see the router note.)
const REPO_URL = 'https://tarotgen.io/plugin/repo.json'

const { success: toastSuccess, error: toastError } = useToasts()

// Things the plugin can do in-game, mirroring the plugin's in-scope feature set.
const features = [
  {
    icon: byPrefixAndName.fas.cards,
    title: 'Draw a reading',
    body: 'Pick a public spread or do a free draw (with reversals) without leaving the game.',
  },
  {
    icon: byPrefixAndName.fas['crystal-ball'],
    title: 'View by share code',
    body: 'Open any reading — including password-protected ones — straight from its code.',
  },
  {
    icon: byPrefixAndName.fas.link,
    title: 'Link your account',
    body: 'Optional: lock readings, sort pickers by your favorites, and track your history.',
  },
  {
    icon: byPrefixAndName.fas['paper-plane'],
    title: 'Send to another player',
    body: 'Push a reading to another plugin user with a passive, chatless in-game prompt.',
  },
  {
    icon: byPrefixAndName.fas['triangle-exclamation'],
    title: 'Report a card scan',
    body: 'Flag a card image with artefacts from the lightbox so it can be re-scanned.',
  },
] as const

// Quick command reference shown beside the install steps.
const commands = [
  { cmd: '/xlsettings', label: 'Custom plugin repositories' },
  { cmd: '/xlplugins', label: 'Install & manage plugins' },
  { cmd: '/tarot', label: 'Open TarotGen' },
] as const

async function copyRepoUrl(): Promise<void> {
  try {
    await navigator.clipboard.writeText(REPO_URL)
    toastSuccess('Repository URL copied to your clipboard.')
  } catch {
    toastError('Could not copy — select the URL and copy it manually.')
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader
            title="FFXIV Plugin"
            subtitle="Draw and view TarotGen.io tarot readings without leaving Final Fantasy XIV."
          />

          <div class="settings-panel">
            <!-- Install -->
            <h2 class="section-title">Install it</h2>
            <div class="columns install-cols">
              <div class="column is-7">
                <p class="lead">
                  Add the custom repository once, then install from the plugin list — all done
                  in-game:
                </p>

                <ol class="steps">
                  <li class="step">
                    <p class="step-title">Open the custom repositories list</p>
                    <p class="step-body">
                      Type <code>/xlsettings</code> in chat, then go to the
                      <strong>Experimental</strong> tab →
                      <strong>Custom Plugin Repositories</strong>.
                    </p>
                  </li>
                  <li class="step">
                    <p class="step-title">Add the TarotGen repository</p>
                    <p class="step-body">
                      Paste this URL into a new row, then click <strong>Save</strong>:
                    </p>
                    <div class="repo-url">
                      <code class="repo-url-code">{{ REPO_URL }}</code>
                      <button
                        type="button"
                        class="button is-small copy-btn"
                        aria-label="Copy repository URL"
                        @click="copyRepoUrl"
                      >
                        <span class="icon"
                          ><FontAwesomeIcon :icon="byPrefixAndName.fas['copy']"
                        /></span>
                        <span>Copy</span>
                      </button>
                    </div>
                  </li>
                  <li class="step">
                    <p class="step-title">Install &amp; open</p>
                    <p class="step-body">
                      Type <code>/xlplugins</code>, find <strong>TarotGen</strong>, and click
                      <strong>Install</strong>. Then run <code>/tarot</code> in chat to open it.
                    </p>
                  </li>
                </ol>
              </div>

              <div class="column is-5">
                <aside class="aside">
                  <h3 class="aside-title">What you'll need</h3>
                  <ul class="aside-list">
                    <li>Final Fantasy XIV on Windows</li>
                    <li>
                      Launched via
                      <a
                        href="https://goatcorp.github.io/"
                        target="_blank"
                        rel="noopener noreferrer"
                        >XIVLauncher</a
                      >, which provides <strong>Dalamud</strong>
                    </li>
                    <li>Both are free and open source</li>
                  </ul>

                  <h3 class="aside-title">In-game commands</h3>
                  <dl class="cmd-list">
                    <div v-for="c in commands" :key="c.cmd" class="cmd">
                      <dt>
                        <code>{{ c.cmd }}</code>
                      </dt>
                      <dd>{{ c.label }}</dd>
                    </div>
                  </dl>
                </aside>
              </div>
            </div>

            <div class="rule" aria-hidden="true"></div>

            <!-- What it does -->
            <h2 class="section-title">What you can do</h2>
            <div class="feature-grid">
              <div v-for="f in features" :key="f.title" class="feature">
                <span class="feature-icon"><FontAwesomeIcon :icon="f.icon" /></span>
                <div>
                  <p class="feature-title">{{ f.title }}</p>
                  <p class="feature-body">{{ f.body }}</p>
                </div>
              </div>
            </div>

            <div class="rule" aria-hidden="true"></div>

            <!-- Account linking + help -->
            <p class="note">
              <FontAwesomeIcon :icon="byPrefixAndName.fas['lock']" class="note-icon" />
              <span>
                <strong>Linking an account is optional.</strong> Guests can draw, view, and share
                readings; the first draw asks once, in your browser, for a draw key (a quick check
                that you're a person). Link a TarotGen account from the plugin's settings to lock
                readings, sort by favorites, and track them in
                <router-link :to="{ name: 'account-readings' }">My Readings</router-link> —
                revocable any time from
                <router-link :to="{ name: 'account-settings' }">Account Settings</router-link>.
              </span>
            </p>

            <p class="help-line">
              Trouble getting set up?
              <router-link :to="{ name: 'contact' }">Contact us</router-link> and we'll help.
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* A single framed panel with flat contents — structure comes from type,
   spacing, and hairline rules rather than nested boxes. */

.section-title {
  font-family: var(--myst-heading-font);
  letter-spacing: 0.02em;
  font-size: 1.6rem;
  line-height: 1.1;
  color: var(--myst-text-strong);
  margin-bottom: 0.75rem;
}

.lead {
  color: var(--myst-text-muted);
  margin-bottom: 1.75rem;
}

/* Faint gold hairline separating sections — echoes the PageHeader rule. */
.rule {
  height: 1px;
  margin: 2.5rem 0;
  background: linear-gradient(
    90deg,
    var(--myst-hair-gold) 0%,
    color-mix(in srgb, var(--myst-gold) 15%, transparent) 55%,
    transparent 100%
  );
}

/* Numbered install steps: a thin gold numeral, no card behind it. */
.steps {
  list-style: none;
  margin: 0;
  counter-reset: step;
}

.step {
  position: relative;
  padding-left: 3.25rem;
  margin-bottom: 1.6rem;
}

.step:last-child {
  margin-bottom: 0;
}

.step::before {
  counter-increment: step;
  content: counter(step);
  position: absolute;
  left: 0;
  top: -0.15rem;
  width: 2.1rem;
  height: 2.1rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1px solid var(--myst-hair-gold);
  color: var(--myst-gold);
  font-family: var(--myst-heading-font);
  font-size: 1.05rem;
  font-weight: 600;
}

.step-title {
  font-weight: 600;
  color: var(--myst-text);
  margin-bottom: 0.3rem;
}

.step-body {
  color: var(--myst-text-muted);
}

.repo-url {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  margin-top: 0.75rem;
}

.repo-url-code {
  flex: 1 1 auto;
  min-width: 0;
  overflow-x: auto;
  white-space: nowrap;
  padding: 0.45rem 0.7rem;
  background: var(--myst-bg-2);
  border: 1px solid var(--myst-border-strong);
  border-radius: 8px;
  color: var(--myst-gold-bright);
}

.copy-btn {
  flex: none;
}

/* Right-hand reference column beside the steps — set off by a hairline,
   not a filled box. */
.install-cols {
  margin-top: 0.25rem;
}

.aside-title {
  font-family: var(--myst-heading-font);
  letter-spacing: 0.02em;
  font-size: 1.15rem;
  color: var(--myst-text-strong);
  margin-bottom: 0.65rem;
}

.aside-list {
  list-style: none;
  margin: 0 0 1.75rem;
  color: var(--myst-text-muted);
}

.aside-list li {
  position: relative;
  padding-left: 1.1rem;
  margin-bottom: 0.55rem;
}

.aside-list li::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0.55rem;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--myst-gold);
}

.cmd-list {
  margin: 0;
}

.cmd {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin-bottom: 0.9rem;
}

.cmd:last-child {
  margin-bottom: 0;
}

.cmd dt {
  align-self: flex-start;
}

.cmd code {
  padding: 0.2rem 0.55rem;
  background: var(--myst-bg-2);
  border: 1px solid var(--myst-border-strong);
  border-radius: 6px;
  color: var(--myst-gold-bright);
  font-size: 0.85rem;
}

.cmd dd {
  margin: 0;
  color: var(--myst-text-muted);
  font-size: 0.85rem;
}

/* Feature list: gold icon beside a short blurb, laid out in a plain grid. */
.feature-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.75rem 2.5rem;
}

.feature {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
}

.feature-icon {
  flex: none;
  width: 1.6rem;
  text-align: center;
  font-size: 1.2rem;
  color: var(--myst-gold);
  margin-top: 0.1rem;
}

.feature-title {
  font-weight: 600;
  color: var(--myst-text);
  margin-bottom: 0.15rem;
}

.feature-body {
  font-size: 0.92rem;
  color: var(--myst-text-muted);
}

/* Optional-account note: a quiet inline line, not a filled callout. */
.note {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  max-width: 74ch;
  color: var(--myst-text-muted);
}

.note-icon {
  flex: none;
  margin-top: 0.25rem;
  color: var(--myst-gold);
}

.help-line {
  text-align: center;
  color: var(--myst-text-dim);
  margin-top: 2.5rem;
}

/* Desktop/tablet: the reference column gets a hairline divider from the steps. */
@media screen and (width >= 769px) {
  .aside {
    border-left: 1px solid var(--myst-hair-gold);
    padding-left: 1.9rem;
    height: 100%;
  }
}

@media screen and (width <= 768px) {
  .feature-grid {
    grid-template-columns: 1fr;
  }
}
</style>
