<script setup lang="ts">
import { computed } from 'vue'
import { byPrefixAndName } from '@/fontawesome'

const props = defineProps<{
  label: string
  sortKey: string
  activeKey: string | null
  dir: 'asc' | 'desc'
}>()

const emit = defineEmits<{ sort: [string] }>()

// Only the column the table is sorted by says so; the rest carry no aria-sort.
const ariaSort = computed(() =>
  props.activeKey === props.sortKey
    ? props.dir === 'asc'
      ? 'ascending'
      : 'descending'
    : undefined,
)
</script>

<template>
  <!-- The cell takes the click, so all of it sorts; the button inside is what
       keyboard users reach, and its Enter/Space click bubbles up to here. -->
  <th class="sortable-th" :aria-sort="ariaSort" @click="emit('sort', sortKey)">
    <button type="button" class="sortable-th__button">
      <span>{{ label }}</span>
      <span class="icon is-small sort-icon">
        <FontAwesomeIcon
          :icon="
            activeKey === sortKey
              ? dir === 'asc'
                ? byPrefixAndName.fas['sort-up']
                : byPrefixAndName.fas['sort-down']
              : byPrefixAndName.fas['sort']
          "
        />
      </span>
    </button>
  </th>
</template>

<style scoped>
.sortable-th {
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}

.sortable-th:hover {
  color: var(--myst-gold);
}

/* Reads as plain header text: it inherits the cell's font, colour and case. */
.sortable-th__button {
  display: inline-flex;
  align-items: center;
  gap: 0.25em;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  cursor: inherit;
}

.sortable-th__button:focus-visible {
  outline: 1px solid var(--myst-hair-gold);
  outline-offset: 3px;
  border-radius: 2px;
}

.sort-icon {
  opacity: 0.55;
}
</style>
