<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { usePluginLink } from '@/composables/usePluginLink'
import { pluginRequest } from '@/utils/pluginRequest'
import PageHeader from '@/components/PageHeader.vue'

// The plugin opens this page with the PKCE + loopback query params.
const route = useRoute()
const { currentUser, isLoggedIn, fetchMe } = useUser()
const { authorize } = usePluginLink()

const { params, valid: paramsValid } = pluginRequest(route.query)

const checkingSession = ref(true)
const submitting = ref(false)
const error = ref<string | null>(null)
const done = ref(false)

onMounted(async () => {
  // Revalidate against the server directly so we reflect the real login state
  // (an already-signed-in user should go straight to the consent, never /login).
  await fetchMe()
  checkingSession.value = false
})

// A login link that returns to THIS page (with its query) once signed in.
const loginTo = computed(() => ({ name: 'login', query: { redirect: route.fullPath } }))

async function approve(): Promise<void> {
  submitting.value = true
  error.value = null

  finish(await authorize(params))
}

function finish(res: { ok: boolean; redirectUri?: string; error?: string }): void {
  submitting.value = false
  if (res.ok && res.redirectUri) {
    done.value = true
    // Hand the authorization code back to the plugin's loopback listener.
    window.location.href = res.redirectUri
  } else {
    error.value = res.error ?? 'Connection failed.'
  }
}
</script>

<template>
  <section class="section">
    <!-- eslint-disable-next-line vue/no-static-inline-styles -- the page's own width: a class would lose to Bulma's .container:not(…) breakpoint rules (four classes' weight) on wide screens -->
    <div class="container" style="max-width: 40rem">
      <PageHeader
        title="Connect the TarotGen Plugin"
        subtitle="Authorize the TarotGen FFXIV plugin to connect to your account."
      />

      <div v-if="!paramsValid" class="myst-callout">
        This connection link is missing or has invalid parameters. Please start it from the plugin's
        settings in-game.
      </div>

      <div v-else-if="checkingSession" class="has-text-grey">Checking your session…</div>

      <div v-else-if="done" class="notification is-success">
        <p><strong>Connected.</strong> You can close this tab and return to the game.</p>
      </div>

      <div v-else class="settings-panel">
        <p v-if="error" class="help is-danger mb-3">{{ error }}</p>

        <div class="mb-4">
          <p class="mb-1"><strong>Link your account</strong></p>
          <p class="is-size-7 has-text-grey mb-2">
            Unlocks locking readings, favorites, and My Readings. The plugin
            <strong>cannot</strong> change your password, delete your account, or manage other
            links, and you can revoke it any time from Account Settings.
          </p>
          <button
            v-if="isLoggedIn"
            type="button"
            class="button is-primary"
            :class="{ 'is-loading': submitting }"
            :disabled="submitting"
            @click="approve"
          >
            Authorize as {{ currentUser?.display_name }}
          </button>
          <router-link v-else class="button is-primary" :to="loginTo">
            Log in to link your account
          </router-link>
        </div>

        <div class="mt-4">
          <router-link class="button is-text" :to="{ name: 'home' }">Cancel</router-link>
        </div>
      </div>
    </div>
  </section>
</template>
