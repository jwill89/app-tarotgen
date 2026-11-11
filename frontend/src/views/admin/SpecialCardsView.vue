<script setup lang="ts">
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import { useToasts } from '@/composables/useToasts'
import BaseModal from '@/components/BaseModal.vue'
import IconButton from '@/components/IconButton.vue'
import NumberField from '@/components/NumberField.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { SpecialCard, Deck } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const specialCards = ref<SpecialCard[]>([])
const decks = ref<Deck[]>([])
const filterDeckId = ref<number | null>(null)
const editing = ref<Partial<SpecialCard> | null>(null)
const isNew = ref(false)
const saving = ref(false)

const deckLookup = computed(() => {
  const map: Partial<Record<number, Deck>> = {}
  decks.value.forEach((d) => {
    map[d.deck_id] = d
  })
  return map
})

// First narrow by the deck dropdown, then apply search + sort.
const deckFiltered = computed(() =>
  filterDeckId.value === null
    ? specialCards.value
    : specialCards.value.filter((c) => c.deck_id === filterDeckId.value),
)

// Plain deck options for the modal's deck picker.
const deckOptions = computed(() => decks.value.map((d) => ({ value: d.deck_id, label: d.name })))

// Filter picker (Reka Select via BaseSelect): 0 is the "All Decks" sentinel, so
// filterDeckId stays number | null and the fetch/filter null-checks are unchanged.
const filterDeckOptions = computed(() => [
  { value: 0, label: 'All Decks' },
  ...decks.value.map((d) => ({ value: d.deck_id, label: d.name })),
])
const filterDeckModel = computed<number>({
  get: () => filterDeckId.value ?? 0,
  set: (v) => {
    filterDeckId.value = v === 0 ? null : v
  },
})

const {
  search,
  sortKey,
  sortDir,
  rows: visibleCards,
  toggleSort,
} = useDataTable(deckFiltered, {
  searchText: (c) => `${c.name} ${c.card_id} ${deckLookup.value[c.deck_id]?.name ?? ''}`,
  sortAccessors: {
    deck: (c) => deckLookup.value[c.deck_id]?.name ?? '',
    card_id: (c) => c.card_id,
    name: (c) => c.name,
  },
  initialSort: 'deck',
})

async function fetchData() {
  // Special cards are nested under decks (`/decks/{id}/special-cards`), so
  // there is no single "all cards" endpoint — fetch decks first, then each
  // deck's special cards and flatten. Always every deck, even with a deck
  // filter on: the filter is applied client-side, and fetching only the
  // filtered deck would replace the whole list with it, so switching back to
  // "All Decks" after a save or delete would show only that one deck's cards.
  const dk = await api.get<Deck[]>(endpoints.admin.decks.list)
  if (dk) {
    decks.value = dk
  }

  const results = await Promise.all(
    decks.value.map((d) => api.get<SpecialCard[]>(endpoints.admin.decks.specialCards(d.deck_id))),
  )
  specialCards.value = results.flatMap((r) => r ?? [])
}

function openAdd() {
  isNew.value = true
  editing.value = {
    deck_id: filterDeckId.value ?? decks.value[0]?.deck_id,
    card_id: undefined,
    name: '',
  }
}

function openEdit(card: SpecialCard) {
  isNew.value = false
  editing.value = { ...card }
}

function closeEdit() {
  editing.value = null
}

async function saveCard() {
  if (!editing.value) {
    return
  }
  const { deck_id: deckId, card_id: cardId } = editing.value
  if (deckId === undefined) {
    toasts.error('Choose the deck this card belongs to.')
    return
  }
  saving.value = true
  try {
    let result
    if (isNew.value) {
      result = await api.post(
        endpoints.admin.decks.specialCards(deckId),
        editing.value,
        'Special card created.',
      )
    } else if (cardId !== undefined) {
      result = await api.put(
        endpoints.admin.decks.specialCardById(deckId, cardId),
        editing.value,
        'Special card updated.',
      )
    }
    if (!result) {
      return
    }
    await fetchData()
    closeEdit()
  } finally {
    saving.value = false
  }
}

async function deleteCard(card: SpecialCard) {
  const deckName = deckLookup.value[card.deck_id]?.name ?? `deck ${card.deck_id}`
  const ok = await confirm({
    title: 'Delete special card',
    message: `Delete "${card.name}" (Card ID ${card.card_id}) from ${deckName}? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }
  const result = await api.del(
    endpoints.admin.decks.specialCardById(card.deck_id, card.card_id),
    'Special card deleted.',
  )
  if (result) {
    await fetchData()
  }
}

onMounted(fetchData)
</script>

<template>
  <AdminListPage
    title="Manage Special Cards"
    subtitle="Manage extra cards specific to certain decks."
  >
    <template #actions>
      <button type="button" class="button is-primary" @click="openAdd">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
        <span>Add Special Card</span>
      </button>
    </template>

    <div class="columns">
      <div class="column is-4">
        <div class="field">
          <label for="special-filter-by-deck" class="label">Filter by Deck</label>
          <BaseSelect
            id="special-filter-by-deck"
            v-model="filterDeckModel"
            :options="filterDeckOptions"
          />
        </div>
      </div>
      <div class="column is-8">
        <AdminSearchField
          v-model="search"
          label="Search"
          placeholder="Search by name, card ID, or deck..."
        />
      </div>
    </div>

    <div class="settings-panel">
      <div class="table-container">
        <table class="table is-fullwidth is-hoverable is-striped">
          <thead>
            <tr>
              <SortableTh
                label="Deck"
                sort-key="deck"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <th>System</th>
              <SortableTh
                label="Card ID"
                sort-key="card_id"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Name"
                sort-key="name"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="card in visibleCards" :key="card.deck_id + '-' + card.card_id">
              <td>{{ deckLookup[card.deck_id]?.name ?? card.deck_id }}</td>
              <td>
                <span class="data-chip">{{
                  deckLookup[card.deck_id]?.system_short_name ?? '—'
                }}</span>
              </td>
              <td>{{ card.card_id }}</td>
              <td>{{ card.name }}</td>
              <td>
                <div class="row-actions">
                  <IconButton
                    :icon="byPrefixAndName.fas['pen-to-square']"
                    label="Edit"
                    @click="openEdit(card)"
                  />
                  <IconButton
                    :icon="byPrefixAndName.fas['trash']"
                    label="Delete"
                    intent="danger"
                    @click="deleteCard(card)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <p v-if="visibleCards.length === 0" class="has-text-grey">No special cards found.</p>
  </AdminListPage>

  <!-- Add/Edit Modal -->
  <BaseModal
    :active="editing !== null"
    :title="isNew ? 'Add Special Card' : 'Edit Special Card'"
    max-width="600px"
    @close="closeEdit"
  >
    <template v-if="editing">
      <div class="field">
        <label for="special-deck" class="label">Deck</label>
        <BaseSelect
          id="special-deck"
          v-model="editing.deck_id"
          :options="deckOptions"
          :disabled="!isNew"
          placeholder="Select a deck…"
        />
      </div>
      <div class="field">
        <label for="special-card-id" class="label">Card ID</label>
        <NumberField id="special-card-id" v-model="editing.card_id" :min="1" :disabled="!isNew" />
      </div>
      <div class="field">
        <label for="special-name" class="label">Name</label>
        <input id="special-name" v-model="editing.name" class="input" />
      </div>
      <div class="field">
        <label for="special-keywords" class="label">Keywords</label>
        <input
          id="special-keywords"
          v-model="editing.keywords"
          class="input"
          placeholder="comma, separated, keywords"
        />
      </div>
      <div class="field">
        <label for="special-meaning" class="label">Meaning</label>
        <textarea
          id="special-meaning"
          v-model="editing.meaning"
          class="textarea"
          rows="3"
        ></textarea>
      </div>
      <div class="field">
        <label for="special-advice" class="label">Advice</label>
        <textarea id="special-advice" v-model="editing.advice" class="textarea" rows="3"></textarea>
      </div>
      <div class="field">
        <label for="special-keywords-reversed" class="label">Keywords (Reversed)</label>
        <input
          id="special-keywords-reversed"
          v-model="editing.keywords_reversed"
          class="input"
          placeholder="comma, separated, keywords"
        />
      </div>
      <div class="field">
        <label for="special-meaning-reversed" class="label">Meaning (Reversed)</label>
        <textarea
          id="special-meaning-reversed"
          v-model="editing.meaning_reversed"
          class="textarea"
          rows="3"
        ></textarea>
      </div>
      <div class="field">
        <label for="special-advice-reversed" class="label">Advice (Reversed)</label>
        <textarea
          id="special-advice-reversed"
          v-model="editing.advice_reversed"
          class="textarea"
          rows="3"
        ></textarea>
      </div>
    </template>

    <template #footer>
      <button type="button" class="button" @click="closeEdit">Cancel</button>
      <button
        type="button"
        class="button is-success"
        :class="{ 'is-loading': saving }"
        @click="saveCard"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
        <span>{{ isNew ? 'Create' : 'Save' }}</span>
      </button>
    </template>
  </BaseModal>
</template>
