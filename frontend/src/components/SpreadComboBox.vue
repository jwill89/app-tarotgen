<script setup lang="ts">
/**
 * Spread picker built on Reka UI's Combobox. Its API is `options` +
 * `modelValue` (a SpreadOption or null), `update:modelValue`, and a
 * `free-draw` emit. The null "No Spread (Free Draw)" choice is modelled as a
 * sentinel value so it can live in Reka's value-keyed selection, then mapped back
 * to null + the `free-draw` emit. Favourites, then My Spreads, then the public
 * spreads under their library categories (in the categories' order), each item
 * with its favourite star.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed } from 'vue'
import type { SpreadOption } from '@/types'
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
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { useUser } from '@/composables/useUser'
import Tooltip from '@/components/Tooltip.vue'

const props = defineProps<{
  options: SpreadOption[]
  modelValue: SpreadOption | null
  /** Put on the search input, so a `<label for>` names the combobox. */
  id?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: SpreadOption | null]
  'free-draw': []
}>()

const { toggleFavorite } = useFavoriteSpreads()
const { isLoggedIn } = useUser()

// What the user has typed since the list opened. Reka keeps its own copy
// internal (ComboboxRoot has no searchTerm model in v2), and the input's text
// is the selection's name until they type, so it's taken from input events.
const searchTerm = ref('')

// Sentinel so "No Spread (Free Draw)" is a real, value-keyed Combobox choice.
const FREE_DRAW_ID = '__free_draw__'
const FREE_DRAW = { id: FREE_DRAW_ID } as unknown as SpreadOption
const FREE_DRAW_LABEL = 'No Spread (Free Draw)'

// No spread (null) is free draw, however it came about: a parent resetting the
// form to null still shows the choice in the input, as nothing is tracked here.
const selected = computed<SpreadOption>({
  get: () => props.modelValue ?? FREE_DRAW,
  set: (val) => {
    if (val.id === FREE_DRAW_ID) {
      emit('update:modelValue', null)
      emit('free-draw')
    } else {
      emit('update:modelValue', val)
    }
  },
})

const filtered = computed(() => {
  const q = searchTerm.value.toLowerCase().trim()
  if (!q) {
    return props.options
  }
  return props.options.filter((o) => o.name.toLowerCase().includes(q))
})

// Offered while browsing, and to a search that names it: otherwise, as the
// first item, it's what Enter would pick after typing a spread's name.
const showFreeDraw = computed(() => {
  const q = searchTerm.value.toLowerCase().trim()
  return !q || FREE_DRAW_LABEL.toLowerCase().includes(q)
})

interface OptionGroup {
  key: string
  label: string
  items: SpreadOption[]
}

const groupedOptions = computed<OptionGroup[]>(() => {
  const favs = filtered.value.filter((o) => o.isFavorite)
  const personal = filtered.value.filter((o) => o.type === 'personal' && !o.isFavorite)
  const pub = filtered.value.filter((o) => o.type === 'public' && !o.isFavorite)
  const groups: OptionGroup[] = []
  if (favs.length > 0) {
    groups.push({ key: 'favorites', label: 'Favorites', items: favs })
  }
  if (personal.length > 0) {
    groups.push({ key: 'personal', label: 'My Spreads', items: personal })
  }
  return [...groups, ...publicGroups(pub)]
})

// Public spreads under their library category, in the categories' order. One
// given without a category goes under "Public Spreads", after the rest.
function publicGroups(options: SpreadOption[]): OptionGroup[] {
  const byCategory = new Map<string, { order: number; items: SpreadOption[] }>()
  for (const option of options) {
    const label = option.category ?? 'Public Spreads'
    const order = option.category ? (option.categoryOrder ?? 0) : Number.POSITIVE_INFINITY
    const group = byCategory.get(label)
    if (group) {
      group.items.push(option)
    } else {
      byCategory.set(label, { order, items: [option] })
    }
  }
  return [...byCategory.entries()]
    .sort(([, a], [, b]) => a.order - b.order)
    .map(([label, { items }]) => ({ key: 'public-' + label, label, items }))
}

function displayValue(val: SpreadOption | undefined): string {
  if (!val) {
    return ''
  }
  return val.id === FREE_DRAW_ID ? FREE_DRAW_LABEL : val.name
}

// Clear the search as the list closes, so it reopens showing every spread.
function onOpenChange(open: boolean): void {
  if (!open) {
    searchTerm.value = ''
  }
}

function onInput(event: Event): void {
  searchTerm.value = (event.target as HTMLInputElement).value
}

function selectFreeDraw(): void {
  selected.value = FREE_DRAW
}

function onToggleFavorite(option: SpreadOption): void {
  if (option.type === 'personal') {
    if (option.user_spread_id != null) {
      void toggleFavorite('personal', option.user_spread_id)
    }
  } else if (option.spread_id != null) {
    void toggleFavorite('public', option.spread_id)
  }
}
</script>

<template>
  <ComboboxRoot
    v-model="selected"
    by="id"
    ignore-filter
    reset-search-term-on-blur
    class="myst-combobox"
    @update:open="onOpenChange"
  >
    <ComboboxAnchor
      class="myst-combobox__anchor control has-icons-left"
      :class="{ 'has-clear': !!modelValue }"
    >
      <span class="icon is-small is-left">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['table-cells']" />
      </span>
      <ComboboxInput
        :id="id"
        class="input"
        :display-value="displayValue"
        placeholder="Search spreads..."
        @input="onInput"
      />
      <button
        v-if="modelValue"
        type="button"
        class="myst-combobox__clear"
        aria-label="Clear spread (free draw)"
        @pointerdown.prevent="selectFreeDraw"
      >
        <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
      </button>
      <ComboboxTrigger class="myst-combobox__trigger" aria-label="Toggle spreads">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']" />
      </ComboboxTrigger>
    </ComboboxAnchor>

    <ComboboxPortal>
      <ComboboxContent class="myst-combobox__content" position="popper" :side-offset="4">
        <ComboboxViewport class="myst-combobox__viewport">
          <ComboboxItem
            v-if="showFreeDraw"
            :value="FREE_DRAW"
            class="myst-combobox__item myst-combobox__item--none"
          >
            {{ FREE_DRAW_LABEL }}
          </ComboboxItem>

          <ComboboxEmpty class="myst-combobox__empty">No spreads found.</ComboboxEmpty>

          <ComboboxGroup
            v-for="group in groupedOptions"
            :key="group.key"
            class="myst-combobox__group-wrap"
          >
            <ComboboxLabel class="myst-combobox__group">{{ group.label }}</ComboboxLabel>
            <ComboboxItem
              v-for="option in group.items"
              :key="option.id"
              :value="option"
              class="myst-combobox__item"
            >
              <span class="myst-combobox__item-name">{{ option.name }}</span>
              <span class="myst-combobox__card-count ml-2">
                {{ option.card_count }} card{{ option.card_count === 1 ? '' : 's' }}
              </span>
              <Tooltip
                v-if="isLoggedIn"
                :text="option.isFavorite ? 'Remove from favorites' : 'Add to favorites'"
              >
                <!-- eslint-disable-next-line vuejs-accessibility/click-events-have-key-events, vuejs-accessibility/interactive-supports-focus -- a focusable control can't sit inside a listbox option, so keyboard users can't favourite from the list -->
                <span
                  class="myst-combobox__star ml-auto"
                  :class="{ 'is-active': option.isFavorite }"
                  :aria-label="option.isFavorite ? 'Remove from favorites' : 'Add to favorites'"
                  role="button"
                  @pointerdown.stop.prevent
                  @click.stop.prevent="onToggleFavorite(option)"
                >
                  <FontAwesomeIcon
                    :icon="
                      option.isFavorite ? byPrefixAndName.fas['star'] : byPrefixAndName.far['star']
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
