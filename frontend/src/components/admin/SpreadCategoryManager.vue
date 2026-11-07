<script setup lang="ts">
/**
 * The spread library's categories, kept by admins on the Spread Categories page:
 * add one (at the end), rename, move up or down, and remove an empty one. The
 * server refuses removing one that still holds spreads (they're moved one by
 * one, from the spread editor) and the last one; its message is toasted.
 *
 * The page owns the list (and the heading); after each change this emits
 * `changed` for it to reload.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, nextTick, useId } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { useConfirm } from '@/composables/useConfirm'
import { endpoints } from '@/api/endpoints'
import type { SpreadCategory } from '@/types'
import IconButton from '@/components/IconButton.vue'

const props = defineProps<{ categories: SpreadCategory[] }>()
const emit = defineEmits<{ changed: [] }>()

const api = useAdminApi()
const { confirm } = useConfirm()
const uid = useId()

const newName = ref('')
const adding = ref(false)
const renamingId = ref<number | null>(null)
const renameText = ref('')
const busy = ref(false)

function spreadsIn(category: SpreadCategory): string {
  return category.spread_count === 1 ? '1 spread' : `${category.spread_count} spreads`
}

async function add(): Promise<void> {
  const name = newName.value.trim()
  if (!name || adding.value) {
    return
  }
  adding.value = true
  const created = await api.post<SpreadCategory>(
    endpoints.admin.spreadCategories.list,
    { name },
    `Added “${name}”.`,
  )
  adding.value = false
  if (created) {
    newName.value = ''
    emit('changed')
  }
}

async function startRename(category: SpreadCategory): Promise<void> {
  renamingId.value = category.category_id
  renameText.value = category.name
  await nextTick()
  document.getElementById(`${uid}-rename-${category.category_id}`)?.focus()
}

function cancelRename(): void {
  renamingId.value = null
}

async function saveRename(category: SpreadCategory): Promise<void> {
  const name = renameText.value.trim()
  if (!name || name === category.name) {
    cancelRename()
    return
  }
  busy.value = true
  const renamed = await api.patch<SpreadCategory>(
    endpoints.admin.spreadCategories.byId(category.category_id),
    { name },
    'Category renamed.',
  )
  busy.value = false
  if (renamed) {
    cancelRename()
    emit('changed')
  }
}

async function move(index: number, by: -1 | 1): Promise<void> {
  const ids = props.categories.map((c) => c.category_id)
  const target = index + by
  const here = ids[index]
  const there = ids[target]
  // Off either end of the list: nothing to swap with.
  if (here === undefined || there === undefined) {
    return
  }
  ids[index] = there
  ids[target] = here
  busy.value = true
  const reordered = await api.put<SpreadCategory[]>(endpoints.admin.spreadCategories.order, {
    category_ids: ids,
  })
  busy.value = false
  if (reordered) {
    emit('changed')
  }
}

async function remove(category: SpreadCategory): Promise<void> {
  const ok = await confirm({
    title: 'Remove category',
    message: `Remove “${category.name}” from the spread library?`,
    confirmLabel: 'Remove',
    danger: true,
  })
  if (!ok) {
    return
  }
  busy.value = true
  const removed = await api.del(
    endpoints.admin.spreadCategories.byId(category.category_id),
    'Category removed.',
  )
  busy.value = false
  if (removed) {
    emit('changed')
  }
}
</script>

<template>
  <div class="category-manager">
    <div class="settings-panel">
      <ol class="category-list">
        <li
          v-for="(category, index) in categories"
          :key="category.category_id"
          class="category-row"
        >
          <span class="category-row__order" aria-hidden="true">{{ index + 1 }}</span>

          <form
            v-if="renamingId === category.category_id"
            class="category-row__rename"
            @submit.prevent="saveRename(category)"
          >
            <label :for="`${uid}-rename-${category.category_id}`" class="visually-hidden"
              >New name for {{ category.name }}</label
            >
            <input
              :id="`${uid}-rename-${category.category_id}`"
              v-model="renameText"
              class="input is-small"
              maxlength="60"
              @keydown.escape.prevent="cancelRename"
            />
            <button type="submit" class="button is-small is-success" :disabled="busy">Save</button>
            <button type="button" class="button is-small is-text" @click="cancelRename">
              Cancel
            </button>
          </form>
          <template v-else>
            <span class="category-row__name">{{ category.name }}</span>
            <span class="category-row__count">{{ spreadsIn(category) }}</span>
          </template>

          <div v-if="renamingId !== category.category_id" class="row-actions">
            <IconButton
              :icon="byPrefixAndName.fas['chevron-up']"
              :label="`Move ${category.name} up`"
              :disabled="busy || index === 0"
              @click="move(index, -1)"
            />
            <IconButton
              :icon="byPrefixAndName.fas['chevron-down']"
              :label="`Move ${category.name} down`"
              :disabled="busy || index === categories.length - 1"
              @click="move(index, 1)"
            />
            <IconButton
              :icon="byPrefixAndName.fas['pen-to-square']"
              :label="`Rename ${category.name}`"
              :disabled="busy"
              @click="startRename(category)"
            />
            <IconButton
              :icon="byPrefixAndName.fas['trash']"
              :label="
                category.spread_count > 0
                  ? `Move its ${spreadsIn(category)} to another category to remove it`
                  : `Remove ${category.name}`
              "
              intent="danger"
              :disabled="busy || category.spread_count > 0 || categories.length === 1"
              @click="remove(category)"
            />
          </div>
        </li>
      </ol>

      <form class="category-add" @submit.prevent="add">
        <label :for="`${uid}-new`" class="label">Add a category</label>
        <div class="field has-addons">
          <div class="control is-expanded">
            <input
              :id="`${uid}-new`"
              v-model="newName"
              class="input"
              maxlength="60"
              placeholder="e.g. Shadow work"
            />
          </div>
          <div class="control">
            <button
              type="submit"
              class="button is-primary"
              :class="{ 'is-loading': adding }"
              :disabled="!newName.trim()"
            >
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
              <span>Add</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.category-list {
  list-style: none;
  margin: 0 0 1.25rem;
}

.category-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 2.75rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid var(--myst-border);
}

.category-row__order {
  width: 1.5rem;
  text-align: right;
  font-size: 0.8rem;
  color: var(--myst-text-dim);
}

.category-row__name {
  flex: 1 1 auto;
  color: var(--myst-text);
}

.category-row__count {
  font-size: 0.8rem;
  color: var(--myst-text-muted);
  white-space: nowrap;
}

.category-row__rename {
  flex: 1 1 auto;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.category-row__rename .input {
  max-width: 20rem;
}

.category-add .field {
  max-width: 28rem;
}
</style>
