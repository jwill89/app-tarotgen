<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useSubmissions } from '@/composables/useSubmissions'
import { useToasts } from '@/composables/useToasts'
import type { SpreadPosition } from '@/types'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'
import PageHeader from '@/components/PageHeader.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'

const router = useRouter()
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')
const { currentUser, isLoggedIn } = useUser()
const { createUserSpread } = useUserSpreads()
const { submitSpread: sendSpread } = useSubmissions()
const toasts = useToasts()

const submitted = ref(false)
const saveMode = ref<'public' | 'personal'>('public')

const saveModeOptions = [
  { value: 'public', label: 'Public Spread', icon: byPrefixAndName.fas.globe },
  { value: 'personal', label: 'Personal Spread', icon: byPrefixAndName.fas.lock },
]

async function submitSpread(payload: {
  name: string
  description: string
  card_count: number
  positions: SpreadPosition[]
}) {
  if (saveMode.value === 'personal') {
    const saved = await createUserSpread(payload)
    if (!saved.ok) {
      editorRef.value?.setError(saved.error)
      return
    }
    toasts.success('Spread saved to your personal collection!')
    void router.push({ name: 'account-spreads' })
    return
  }

  // The server's message covers the rate limit too.
  const res = await sendSpread(payload)
  if (!res.ok) {
    editorRef.value?.setError(res.error)
    return
  }

  submitted.value = true
}

function submitAnother() {
  submitted.value = false
}

function goHome() {
  void router.push({ name: 'home' })
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader
            title="Create a Spread"
            :subtitle="
              isLoggedIn
                ? 'Design a tarot spread and save it for personal use, or submit it for review to make it available to everyone on the site.'
                : 'Design a tarot spread and submit it for review. Approved spreads become available to everyone on the site.'
            "
          />

          <!-- Success state -->
          <div v-if="submitted" class="notification is-success">
            <p class="mb-3">
              <strong>Thanks for your submission!</strong>
              Your spread has been sent to the queue and will appear on the site once an admin
              approves it.
            </p>
            <div class="buttons">
              <button type="button" class="button is-link" @click="submitAnother">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
                <span>Create Another</span>
              </button>
              <button type="button" class="button" @click="goHome">Back to Home</button>
            </div>
          </div>

          <!-- Submission form -->
          <template v-else>
            <div class="settings-panel">
              <p class="mb-4">
                <span class="icon-text">
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['user']" /></span>
                  <span>
                    Submitting as <strong>{{ currentUser?.display_name ?? 'Guest' }}</strong>.
                    <template v-if="!currentUser">
                      <router-link :to="{ name: 'login' }">Log in</router-link> to be credited by
                      your display name.
                    </template>
                  </span>
                </span>
              </p>

              <div v-if="isLoggedIn" class="myst-callout mb-5">
                <div class="field mb-0">
                  <span id="submit-spread-save-as" class="label">
                    <span class="icon-text">
                      <span class="icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['bookmark']"
                      /></span>
                      <span>Save as</span>
                    </span>
                  </span>
                  <SegmentedControl
                    v-model="saveMode"
                    :options="saveModeOptions"
                    aria-labelledby="submit-spread-save-as"
                  />
                  <p v-if="saveMode === 'public'" class="help mt-3">
                    Your spread will be submitted for admin review before it becomes available to
                    everyone.
                  </p>
                  <p v-else class="help mt-3">
                    Your spread will be saved privately to your account and only you can use it.
                  </p>
                </div>
              </div>

              <SpreadEditor
                ref="editorRef"
                :spread="null"
                :save-label="saveMode === 'personal' ? 'Save Spread' : 'Submit Spread'"
                @save="submitSpread"
                @cancel="goHome"
              />
            </div>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
