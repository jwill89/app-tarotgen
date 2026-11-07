<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted } from 'vue'
import { local } from '@/utils/storage'

// Predates the `tarot.` namespace in STORAGE_KEYS; renaming it would show the
// banner again to everyone who has dismissed it.
const STORAGE_KEY = 'cookie_notice_dismissed'
const visible = ref(false)

// Through the guarded wrapper: with storage blocked or full, a raw access threw,
// and "Got it" then never hid the banner.
onMounted(() => {
  if (local.get<unknown>(STORAGE_KEY, null) === null) {
    visible.value = true
  }
})

function dismiss() {
  local.set(STORAGE_KEY, 1)
  visible.value = false
}
</script>

<template>
  <!-- A landmark of its own, so its words aren't left outside the page's regions. -->
  <Transition name="cookie-banner">
    <section v-if="visible" class="cookie-banner" aria-label="Cookie notice">
      <div class="cookie-banner__content">
        <span class="cookie-banner__text">
          <FontAwesomeIcon :icon="byPrefixAndName.fas['cookie-bite']" />
          This site uses essential cookies to keep you logged in. No tracking or advertising cookies
          are used.
        </span>
        <button type="button" class="button is-small is-primary" @click="dismiss">Got it</button>
      </div>
    </section>
  </Transition>
</template>

<style scoped>
.cookie-banner {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  background: var(--myst-surface-2);
  border-top: 1px solid var(--myst-border-strong);
  padding: 0.75rem 1.25rem;
  box-shadow: 0 -2px 12px rgb(0 0 0 / 40%);
}

.cookie-banner__content {
  max-width: 960px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  justify-content: center;
}

.cookie-banner__text {
  color: var(--myst-text);
  font-size: 0.875rem;
}

.cookie-banner__text i {
  color: var(--myst-gold);
  margin-right: 0.4em;
}
</style>

<style>
.cookie-banner-enter-active,
.cookie-banner-leave-active {
  transition:
    transform 0.3s ease,
    opacity 0.3s ease;
}

.cookie-banner-enter-from,
.cookie-banner-leave-to {
  transform: translateY(100%);
  opacity: 0;
}
</style>
