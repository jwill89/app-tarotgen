<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import PageHeader from '@/components/PageHeader.vue'
import CardFrequencyPanel from '@/components/CardFrequencyPanel.vue'
import { useUser } from '@/composables/useUser'
import { useRouter } from 'vue-router'

const { logout } = useUser()
const router = useRouter()

async function doLogout() {
  await logout()
  void router.push({ name: 'home' })
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader title="Dashboard" subtitle="Welcome back! Manage your account from here." />

          <!-- The daily ritual gets its own line rather than a fifth tile. -->
          <router-link :to="{ name: 'daily-card' }" class="daily-strip mb-5">
            <span class="daily-strip__icon">
              <FontAwesomeIcon :icon="byPrefixAndName.fad['calendar-day']" />
            </span>
            <span class="daily-strip__text">
              <span class="daily-strip__title">Daily Card</span>
              <span class="daily-strip__sub"
                >Draw today's card, and look back over the days before.</span
              >
            </span>
            <span class="daily-strip__go">
              <span>Today's card</span>
              <FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-right']" />
            </span>
          </router-link>

          <div class="columns is-multiline">
            <div class="column is-3">
              <router-link
                :to="{ name: 'account-readings' }"
                class="box home-action has-text-centered"
              >
                <span class="icon is-large has-text-info">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['scroll']" size="2x" />
                </span>
                <p class="title is-4 mt-3">My Readings</p>
                <p class="subtitle is-6">View &amp; manage your saved readings</p>
              </router-link>
            </div>
            <div class="column is-3">
              <router-link
                :to="{ name: 'account-spreads' }"
                class="box home-action has-text-centered"
              >
                <span class="icon is-large has-text-link">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['table-cells']" size="2x" />
                </span>
                <p class="title is-4 mt-3">My Spreads</p>
                <p class="subtitle is-6">View &amp; manage your custom spreads</p>
              </router-link>
            </div>
            <div class="column is-3">
              <router-link
                :to="{ name: 'account-settings' }"
                class="box home-action has-text-centered"
              >
                <span class="icon is-large has-text-warning">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['gear']" size="2x" />
                </span>
                <p class="title is-4 mt-3">Account Settings</p>
                <p class="subtitle is-6">Update your profile &amp; security</p>
              </router-link>
            </div>
            <div class="column is-3">
              <!-- An action, not a link: a button, so the keyboard can reach it. -->
              <button type="button" class="box home-action has-text-centered" @click="doLogout">
                <span class="icon is-large has-text-danger">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['right-from-bracket']" size="2x" />
                </span>
                <p class="title is-4 mt-3">Logout</p>
                <p class="subtitle is-6">Sign out of your account</p>
              </button>
            </div>
          </div>

          <CardFrequencyPanel class="mt-5" />
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.daily-strip {
  display: flex;
  align-items: center;
  gap: 1.1rem;
  padding: 1rem 1.35rem;
  border: 1px solid var(--myst-hair-gold);
  border-radius: 14px;
  background: linear-gradient(
    90deg,
    color-mix(in srgb, var(--myst-gold) 8%, var(--myst-surface-2)),
    var(--myst-surface-2) 60%
  );
  color: var(--myst-text);
  text-decoration: none;
  transition:
    border-color 0.15s ease,
    transform 0.15s ease;
}

.daily-strip:hover {
  border-color: var(--myst-gold);
  color: var(--myst-text);
  transform: translateY(-1px);
}

.daily-strip:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 35%, transparent);
}

.daily-strip__icon {
  flex: none;
  color: var(--myst-gold);
  font-size: 1.6rem;
}

.daily-strip__text {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.daily-strip__title {
  font-family: var(--myst-heading-font);
  font-size: 1.3rem;
  color: var(--myst-text-strong);
  line-height: 1.2;
}

.daily-strip__sub {
  color: var(--myst-text-muted);
  font-size: 0.95rem;
}

.daily-strip__go {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  flex: none;
  color: var(--myst-gold-bright);
  font-weight: 600;
}

@media screen and (width <= 600px) {
  .daily-strip__go span {
    display: none;
  }
}
</style>
