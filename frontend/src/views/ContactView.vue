<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted, useTemplateRef } from 'vue'
import { useUser } from '@/composables/useUser'
import { useToasts } from '@/composables/useToasts'
import { useContact } from '@/composables/useContact'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import PageHeader from '@/components/PageHeader.vue'
import TurnstileWidget from '@/components/TurnstileWidget.vue'

const { currentUser, isLoggedIn } = useUser()
const { success: toastSuccess, error: toastError } = useToasts()
const { sendMessage } = useContact()

const name = ref('')
const email = ref('')
const message = ref('')
const submitting = ref(false)
const submitted = ref(false)

const turnstileToken = ref('')
const turnstileEnabled = ref(false)
const turnstile = useTemplateRef('turnstile')

onMounted(() => {
  if (currentUser.value) {
    name.value = currentUser.value.display_name
    email.value = currentUser.value.email
  }
})

async function submit() {
  if (!name.value.trim() || !email.value.trim() || !message.value.trim()) {
    toastError('Please fill out all fields.')
    return
  }

  submitting.value = true
  const result = await sendMessage(
    { name: name.value, email: email.value, message: message.value },
    turnstileToken.value,
  )
  submitting.value = false

  if (!result.ok) {
    toastError(result.error)
    // The challenge token is single-use; reset so the visitor can retry.
    turnstile.value?.reset()
    return
  }

  submitted.value = true
  toastSuccess('Your message has been sent!')
}

function resetForm() {
  message.value = ''
  // Spent on the message just sent: the form's fresh widget asks for a new one.
  turnstileToken.value = ''
  submitted.value = false
}
</script>

<template>
  <section class="section">
    <!-- eslint-disable-next-line vue/no-static-inline-styles -- the page's own width: a class would lose to Bulma's .container:not(…) breakpoint rules (four classes' weight) on wide screens -->
    <div class="container" style="max-width: 720px">
      <PageHeader
        title="Contact Us"
        subtitle="Have a question, suggestion, or just want to say hello? Send us a message."
      />

      <div class="settings-panel">
        <div v-if="submitted" class="notification is-success">
          <p class="mb-3">
            <strong>Thanks for reaching out!</strong>
            Your message has been received and we'll get back to you if a reply is needed.
          </p>
          <div class="buttons">
            <button type="button" class="button is-link" @click="resetForm">
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']"
              /></span>
              <span>Send Another Message</span>
            </button>
            <router-link :to="{ name: 'home' }" class="button">Back to Home</router-link>
          </div>
        </div>

        <form v-else @submit.prevent="submit">
          <div class="field">
            <label for="contact-name" class="label">Name</label>
            <div class="control has-icons-left">
              <input
                id="contact-name"
                v-model="name"
                class="input"
                type="text"
                placeholder="Your name"
                :disabled="isLoggedIn"
                required
              />
              <span class="icon is-small is-left"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['user']"
              /></span>
            </div>
          </div>

          <div class="field">
            <label for="contact-email" class="label">Email</label>
            <div class="control has-icons-left">
              <input
                id="contact-email"
                v-model="email"
                class="input"
                type="email"
                placeholder="your@email.com"
                :disabled="isLoggedIn"
                required
              />
              <span class="icon is-small is-left"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['envelope']"
              /></span>
            </div>
          </div>

          <div class="field">
            <span id="contact-message" class="label">Message</span>
            <MarkdownEditor
              v-model="message"
              labelledby="contact-message"
              placeholder="Write your message… Markdown is supported."
              :rows="6"
            />
          </div>

          <TurnstileWidget
            ref="turnstile"
            v-model="turnstileToken"
            v-model:enabled="turnstileEnabled"
          />

          <div class="field">
            <div class="control">
              <button
                type="submit"
                class="button is-primary"
                :class="{ 'is-loading': submitting }"
                :disabled="submitting || (turnstileEnabled && !turnstileToken)"
              >
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']"
                /></span>
                <span>Send Message</span>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
