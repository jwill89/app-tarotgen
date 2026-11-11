<script setup lang="ts">
/**
 * Deck picker built on Reka UI's Combobox. Its API is `decks` + `modelValue`
 * as a deck_id and `update:modelValue`; the list is favourites-first, then
 * grouped by system, each item with its favourite star. Reka provides the
 * keyboard nav, ARIA wiring, and open/close animation.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed } from 'vue'
import type { Deck } from '@/types'
import {
  ComboboxRoot,
  ComboboxAnchor,
  ComboboxInput,
  ComboboxTrigger,
  ComboboxPortal,
  ComboboxContent,
  ComboboxViewport,
  ComboboxGroup,
  ComboboxLabel,
  ComboboxItem,
  ComboboxEmpty,
} from 'reka-ui'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useUser } from '@/composables/useUser'
import Tooltip from '@/components/Tooltip.vue'

const props = defineProps<{
  decks: Deck[]
  modelValue: number | null
  /** Put on the search input, so a `<label for>` names the combobox. */
  id?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { isFavorite, toggleFavorite } = useFavoriteDecks()
const { isLoggedIn } = useUser()

// What the user has typed since the list opened. Reka keeps its own copy
// internal (ComboboxRoot has no searchTerm model in v2), and the input's text
// is the selection's name until they type, so it's taken from input events.
const searchTerm = ref('')

// Reka's v-model holds the selected deck_id; mirror it to the parent's modelValue.
const selected = computed<number | null>({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const filtered = computed(() => {
  const q = searchTerm.value.toLowerCase().trim()
  if (!q) {
    return props.decks
  }
  return props.decks.filter(
    (d) =>
      d.name.toLowerCase().includes(q) ||
      d.artist.toLowerCase().includes(q) ||
      d.system_short_name.toLowerCase().includes(q),
  )
})

const groupedOptions = computed(() => {
  const favs = filtered.value.filter((d) => isFavorite(d.deck_id))
  const rest = filtered.value.filter((d) => !isFavorite(d.deck_id))

  // A Map, not an object: a system named like an Object.prototype key
  // (`constructor`) is a group like any other.
  const systemGroups = new Map<string, Deck[]>()
  for (const deck of rest) {
    const key = deck.system_short_name || 'Other'
    const group = systemGroups.get(key)
    if (group) {
      group.push(deck)
    } else {
      systemGroups.set(key, [deck])
    }
  }

  const groups: { label: string; items: Deck[] }[] = []
  if (favs.length > 0) {
    groups.push({ label: 'Favorites', items: favs })
  }
  for (const [system, decks] of [...systemGroups].sort((a, b) => a[0].localeCompare(b[0]))) {
    groups.push({ label: system, items: decks })
  }
  return groups
})

/** Show the selected deck's name in the input when it isn't being searched. */
function displayValue(id: number | null): string {
  const d = props.decks.find((x) => x.deck_id === id)
  return d ? d.name : ''
}

// Clear the search as the list closes, so it reopens showing every deck.
function onOpenChange(open: boolean): void {
  if (!open) {
    searchTerm.value = ''
  }
}

function onInput(event: Event): void {
  searchTerm.value = (event.target as HTMLInputElement).value
}

function onToggleFavorite(deck: Deck): void {
  void toggleFavorite(deck.deck_id)
}
</script>

<template>
  <ComboboxRoot
    v-model="selected"
    ignore-filter
    reset-search-term-on-blur
    class="myst-combobox"
    @update:open="onOpenChange"
  >
    <ComboboxAnchor class="myst-combobox__anchor control has-icons-left">
      <span class="icon is-small is-left">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['book']" />
      </span>
      <ComboboxInput
        :id="id"
        class="input"
        :display-value="displayValue"
        placeholder="Search decks..."
        @input="onInput"
      />
      <ComboboxTrigger class="myst-combobox__trigger" aria-label="Toggle decks">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']" />
      </ComboboxTrigger>
    </ComboboxAnchor>

    <ComboboxPortal>
      <ComboboxContent class="myst-combobox__content" position="popper" :side-offset="4">
        <ComboboxViewport class="myst-combobox__viewport">
          <ComboboxEmpty class="myst-combobox__empty">No decks found.</ComboboxEmpty>

          <ComboboxGroup
            v-for="group in groupedOptions"
            :key="group.label"
            class="myst-combobox__group-wrap"
          >
            <ComboboxLabel class="myst-combobox__group">{{ group.label }}</ComboboxLabel>
            <ComboboxItem
              v-for="deck in group.items"
              :key="deck.deck_id"
              :value="deck.deck_id"
              class="myst-combobox__item"
            >
              <span class="myst-combobox__item-name">{{ deck.name }}</span>
              <span class="myst-combobox__item-artist ml-2">{{ deck.artist }}</span>
              <span v-if="deck.additional_cards > 0" class="myst-combobox__extra ml-2">
                +{{ deck.additional_cards }}
              </span>
              <Tooltip
                v-if="isLoggedIn"
                :text="isFavorite(deck.deck_id) ? 'Remove from favorites' : 'Add to favorites'"
              >
                <!-- eslint-disable-next-line vuejs-accessibility/click-events-have-key-events, vuejs-accessibility/interactive-supports-focus -- a focusable control can't sit inside a listbox option, so keyboard users can't favourite from the list -->
                <span
                  class="myst-combobox__star ml-auto"
                  :class="{ 'is-active': isFavorite(deck.deck_id) }"
                  :aria-label="
                    isFavorite(deck.deck_id) ? 'Remove from favorites' : 'Add to favorites'
                  "
                  role="button"
                  @pointerdown.stop.prevent
                  @click.stop.prevent="onToggleFavorite(deck)"
                >
                  <FontAwesomeIcon
                    :icon="
                      isFavorite(deck.deck_id)
                        ? byPrefixAndName.fas['star']
                        : byPrefixAndName.far['star']
                    "
                  />
                </span>
              </Tooltip>
            </ComboboxItem>
          </ComboboxGroup>
        </ComboboxViewport>
      </ComboboxContent>
    </ComboboxPortal>
  </ComboboxRoot>
</template>
