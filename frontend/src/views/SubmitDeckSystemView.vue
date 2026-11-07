<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, useTemplateRef } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import NumberField from '@/components/NumberField.vue'
import DeckCardListEditor from '@/components/DeckCardListEditor.vue'
import {
  resizeCards,
  allCardsNamed,
  missingNameCount,
  type DeckCardListEditorApi,
} from '@/components/deckCards'
import { useUser } from '@/composables/useUser'
import { useSubmissions } from '@/composables/useSubmissions'
import { useToasts } from '@/composables/useToasts'
import type { DeckSystemCard } from '@/types'

const { isLoggedIn } = useUser()
const { submitDeckSystem } = useSubmissions()
const toasts = useToasts()

const name = ref('')
const shortName = ref('')
const totalCards = ref(78)
const cards = ref<DeckSystemCard[]>(resizeCards([], 78))
const submitting = ref(false)
const submitted = ref(false)
const errorMsg = ref('')
const cardEditor = useTemplateRef<DeckCardListEditorApi>('cardEditor')

watch(totalCards, (total) => {
  cards.value = resizeCards(cards.value, total)
})

const allCardTitlesValid = computed(() => allCardsNamed(cards.value))

async function submitSystem() {
  errorMsg.value = ''

  if (!name.value.trim() || !shortName.value.trim()) {
    errorMsg.value = 'Name and Short Name are required.'
    return
  }

  if (totalCards.value < 1) {
    errorMsg.value = 'Total cards must be at least 1.'
    return
  }

  // Validate all cards have names
  const missing = missingNameCount(cards.value)
  if (missing > 0) {
    errorMsg.value = `All ${totalCards.value} cards require a name. ${missing} card(s) are missing names.`
    cardEditor.value?.revealFirstMissing()
    return
  }

  submitting.value = true
  const res = await submitDeckSystem({
    name: name.value.trim(),
    short_name: shortName.value.trim(),
    total_cards: totalCards.value,
    cards: cards.value,
  })
  submitting.value = false

  if (res.ok) {
    submitted.value = true
    toasts.success('Deck system submitted successfully!')
  } else {
    errorMsg.value = res.error
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-8-desktop is-10-tablet">
          <PageHeader
            title="Submit a Deck System"
            subtitle="Propose a new card naming system for decks on TarotGen.io."
          />

          <template v-if="!isLoggedIn">
            <div class="myst-callout">
              <p>
                You must be
                <router-link :to="{ name: 'login', query: { redirect: '/submit-deck-system' } }"
                  >logged in</router-link
                >
                to submit a deck system.
              </p>
            </div>
          </template>

          <template v-else-if="submitted">
            <div class="notification is-success">
              <p>
                <strong>Thank you!</strong> Your deck system submission has been received and will
                be reviewed by an admin.
              </p>
            </div>
            <router-link :to="{ name: 'home' }" class="button is-link">
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['house']" /></span>
              <span>Back to Home</span>
            </router-link>
          </template>

          <template v-else>
            <div class="settings-panel">
              <div class="myst-callout">
                <p>
                  A deck system defines the card names and meanings used by one or more decks. For
                  example, "Rider-Waite-Smith" and "Thoth" are two different deck systems. Each card
                  must have at least a name.
                </p>
              </div>

              <div v-if="errorMsg" class="notification is-danger is-light">
                {{ errorMsg }}
              </div>

              <div class="columns">
                <div class="column is-5">
                  <div class="field">
                    <label for="submit-system-name" class="label"
                      >System Name <span class="has-text-danger">*</span></label
                    >
                    <div class="control">
                      <input
                        id="submit-system-name"
                        v-model="name"
                        class="input"
                        type="text"
                        placeholder="e.g. Marseille"
                      />
                    </div>
                  </div>
                </div>
                <div class="column is-4">
                  <div class="field">
                    <label for="submit-system-short-name" class="label"
                      >Short Name <span class="has-text-danger">*</span></label
                    >
                    <div class="control">
                      <input
                        id="submit-system-short-name"
                        v-model="shortName"
                        class="input"
                        type="text"
                        placeholder="e.g. TdM"
                      />
                    </div>
                    <p class="help">A brief abbreviation shown in deck lists.</p>
                  </div>
                </div>
                <div class="column is-3">
                  <div class="field">
                    <label for="submit-system-total-cards" class="label"
                      >Total Cards <span class="has-text-danger">*</span></label
                    >
                    <div class="control">
                      <NumberField
                        id="submit-system-total-cards"
                        v-model="totalCards"
                        :min="1"
                        :max="200"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <DeckCardListEditor ref="cardEditor" v-model="cards">
                <template #hint>
                  <p class="mb-4 has-text-grey">
                    Enter a name for each card. All other fields are optional but helpful.
                  </p>
                </template>
              </DeckCardListEditor>

              <div class="field mt-5">
                <button
                  type="button"
                  class="button is-primary"
                  :class="{ 'is-loading': submitting }"
                  :disabled="submitting || !allCardTitlesValid"
                  @click="submitSystem"
                >
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']"
                  /></span>
                  <span>Submit Deck System</span>
                </button>
                <p v-if="!allCardTitlesValid" class="help is-danger mt-2">
                  All cards must have a title before submitting.
                </p>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
