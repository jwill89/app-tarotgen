<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, useTemplateRef } from 'vue'
import { useRoute } from 'vue-router'
import { usePluginLink } from '@/composables/usePluginLink'
import { pluginRequest } from '@/utils/pluginRequest'
import PageHeader from '@/components/PageHeader.vue'
import TurnstileWidget from '@/components/TurnstileWidget.vue'

// The plugin opens this page (with PKCE + loopback query params, as /authorize)
// when it isn't linked to an account and has no draw key. The challenge is what
// the page is for: a key lets the plugin skip it on every draw.
const route = useRoute()
const { authorizeDrawKey } = usePluginLink()

const { params, valid: paramsValid } = pluginRequest(route.query)

const turnstileToken = ref('')
const turnstileEnabled = ref(false)
const turnstile = useTemplateRef('turnstile')

const submitting = ref(false)
const error = ref<string | null>(null)
const done = ref(false)

async function getKey(): Promise<void> {
  submitting.value = true
  error.value = null

  const res = await authorizeDrawKey(params, turnstileToken.value)
  submitting.value = false

  if (res.ok && res.redirectUri) {
    done.value = true
    // Hand the one-time code to the plugin's listener, which swaps it for the key.
    window.location.href = res.redirectUri
  } else {
    error.value = res.error ?? 'Could not get a draw key. Please try again.'
    // The challenge token is single-use; reset so the person can try again.
    turnstile.value?.reset()
  }
}
</script>

<template>
  <section class="section">
    <div class="container draw-key-page">
      <PageHeader title="Get a Draw Key" subtitle="For the TarotGen plugin in Final Fantasy XIV." />

      <div v-if="!paramsValid" class="myst-callout">
        This link is missing or has invalid parameters. Please start it from the plugin in-game.
      </div>

      <div v-else-if="done" class="notification is-success">
        <p>
          <strong>Done.</strong> The plugin has its draw key. You can close this tab and return to
          the game.
        </p>
      </div>

      <div v-else class="settings-panel">
        <p class="mb-3">
          A draw key lets the plugin draw readings without an account. This one check shows that a
          person is asking, not a bot; after it, the key stays with the plugin on your computer and
          the plugin draws without asking again.
        </p>
        <p class="is-size-7 has-text-grey mb-4">
          The key only draws readings. To keep your readings, lock them, or see them in My Readings,
          link your account from the plugin's Settings instead.
        </p>

        <TurnstileWidget
          ref="turnstile"
          v-model="turnstileToken"
          v-model:enabled="turnstileEnabled"
        />

        <p v-if="error" class="help is-danger mb-3" role="alert">{{ error }}</p>

        <button
          type="button"
          class="button is-primary"
          :class="{ 'is-loading': submitting }"
          :disabled="submitting || (turnstileEnabled && !turnstileToken)"
          @click="getKey"
        >
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['key']" /></span>
          <span>Get Draw Key</span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.draw-key-page {
  max-width: 40rem;
}
</style>
