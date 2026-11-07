<script setup lang="ts" generic="T extends SpreadSlotBase">
/**
 * Shared spread-canvas editor. Owns the whole interactive canvas surface —
 * pan/zoom, drag-to-move, snap, multi-select, align/distribute/center, undo/redo,
 * the toolbar, the per-card context menu + floating controls, and the unplaced
 * tray — so the three editors (admin SpreadEditor, Recreate Draw, Arrange Draw)
 * don't each re-implement ~485 lines of identical machinery.
 *
 * Callers own their surrounding form + save flow and inject only what differs
 * via a generic `v-model` (the slots array, mutated in place) plus slots:
 *   #card               — interior of a placed card (title span / <img>)
 *   #tray-token         — content of an unplaced-tray button (default: number)
 *   #context-menu-extra — extra ContextMenuItems (e.g. "toggle reversed")
 *   #card-controls-extra— extra floating-control buttons
 *   #edit-modal         — the caller's own BaseModal body ({ slot, index, close })
 *
 * Reset-on-load is done by the caller bumping `:key` (fresh history/zoom/selection
 * baselined on the freshly-loaded slots), so there are no fragile imperative
 * reset calls across the boundary. Undo/redo restores emit `restore(length)` so a
 * caller with a card-count field can resync it.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, useId, useTemplateRef } from 'vue'
import type { ComponentPublicInstance, StyleValue } from 'vue'
import type { SpreadSlotBase } from '@/components/spreadCanvas'
import { usePanZoom } from '@/composables/usePanZoom'
import { useLayoutTools } from '@/composables/useLayoutTools'
import { useUndoRedo } from '@/composables/useUndoRedo'
import { CARD_HALF_H } from '@/composables/useSpreadLayout'
import {
  ToolbarRoot,
  ToolbarButton,
  ToolbarSeparator,
  ContextMenuRoot,
  ContextMenuTrigger,
  ContextMenuPortal,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
} from 'reka-ui'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import Tooltip from '@/components/Tooltip.vue'

const props = withDefaults(
  defineProps<{
    /** Snap grid cell size (%). Also drives the visual grid. */
    snapStep?: number
    /** Max zoom multiplier. */
    zoomMax?: number
    /** Style applied to the viewport (e.g. cardAspectStyle(deck) → --card-aspect). */
    viewportStyle?: StyleValue
    /** Label for the context-menu + floating "edit" action. */
    editLabel?: string
    /** Parenthetical hint next to the tray label. */
    trayHint?: string
  }>(),
  {
    snapStep: 2.5,
    zoomMax: 3,
    viewportStyle: undefined,
    editLabel: 'Edit title…',
    trayHint: '(click to place, then drag to position)',
  },
)
const emit = defineEmits<{
  /** Fired after an undo/redo restore so a caller can resync its card-count. */
  restore: [length: number]
}>()
const slots = defineModel<T[]>({ required: true })
const trayCaptionId = useId()

defineSlots<{
  /** Interior of a placed card (rendered after the order badge). */
  card(props: { item: T; index: number }): unknown
  /** Content of an unplaced-tray button (default: the position number). */
  'tray-token'(props: { item: T; index: number }): unknown
  /** Extra ContextMenuItems, inserted before the Edit / Remove items. */
  'context-menu-extra'(props: { item: T; index: number }): unknown
  /** Extra floating-control buttons, inserted before Edit / Remove. */
  'card-controls-extra'(props: { item: T; index: number }): unknown
  /** The caller's edit-modal body; item/index are null while it's closed. */
  'edit-modal'(props: { item: T | null; index: number | null; close: () => void }): unknown
}>()

const snapToGrid = ref(true)
const editIndex = ref<number | null>(null)
const canvasRef = useTemplateRef<HTMLElement>('canvasRef')
let draggingIndex = -1

// Canvas pan/zoom (on-screen only; coordinates stay 0–100%).
const {
  zoom,
  MIN_ZOOM,
  MAX_ZOOM,
  viewportRef,
  zoomIn,
  zoomOut,
  resetZoom,
  onPanStart,
  onPanMove,
  onPanEnd,
  onWheel,
  wasDragged,
  canvasStyle,
} = usePanZoom({ max: props.zoomMax })

// The viewport element, which pan/zoom measures: a function ref, since the
// composable owns the ref it reads.
function setViewport(el: Element | ComponentPublicInstance | null): void {
  viewportRef.value = el instanceof HTMLElement ? el : null
}

// A background drag pans; a background click (no drag) deselects.
function onCanvasPointerUp(e: PointerEvent) {
  onPanEnd(e)
  if (!wasDragged()) {
    clearSelection()
  }
}

// Each slot with its index, so the template has both without indexing back in.
const unplacedSlots = computed(() =>
  slots.value.map((slot, i) => ({ slot, i })).filter((x) => !x.slot.placed),
)
const placedSlots = computed(() =>
  slots.value.map((slot, i) => ({ slot, i })).filter((x) => x.slot.placed),
)
const unplacedIndexes = computed(() => unplacedSlots.value.map((x) => x.i))
const placedIndexes = computed(() => placedSlots.value.map((x) => x.i))

// Multi-select + alignment/distribution/centering + snap (shared).
const {
  selected,
  selectedCount,
  isSelected,
  setSelection,
  toggleSelection,
  clearSelection,
  pruneSelection,
  snapVal,
  centerAll,
  align,
  distribute,
} = useLayoutTools(slots.value, () => placedIndexes.value, snapToGrid, props.snapStep)

// Undo/redo over the slot layout. Selection is pruned via the length watch below;
// the caller resyncs its own card-count off the `restore` event.
const {
  canUndo,
  canRedo,
  undo,
  redo,
  reset: resetHistory,
  record,
  suspend,
  resume,
} = useUndoRedo(slots.value, () => {
  emit('restore', slots.value.length)
})
// Baseline on the slots as loaded (callers fill them before mounting): with an
// empty history the first change became its first entry and could not be undone.
resetHistory()

// Keep the selection valid whenever the array shrinks (caller resize or restore).
watch(
  () => slots.value.length,
  (len) => pruneSelection(len),
)

// Collapse a modal edit into a single history entry.
watch(editIndex, (val, old) => {
  if (val !== null) {
    suspend()
  } else if (old !== null) {
    resume()
    record()
  }
})

function placeFromTray(index: number) {
  // Cascade new placements so they don't stack exactly.
  const slot = slots.value[index]
  if (!slot) {
    return
  }
  const placedCount = placedIndexes.value.length
  slot.x = snapVal(40 + (placedCount % 5) * 5)
  slot.y = snapVal(40 + Math.floor(placedCount / 5) * 8)
  slot.placed = true
  setSelection([index])
}

function unplace(index: number) {
  const slot = slots.value[index]
  if (!slot) {
    return
  }
  slot.placed = false
  if (selected.value.has(index)) {
    toggleSelection(index)
  }
  if (editIndex.value === index) {
    editIndex.value = null
  }
}

function onCardPointerDown(e: PointerEvent, index: number) {
  e.stopPropagation() // don't let the background pan handler also fire

  // Right/middle click: let the card's context menu handle it — no drag/select.
  if (e.button !== 0) {
    return
  }

  // Shift/Ctrl/Cmd-click toggles multi-selection without starting a drag.
  if (e.shiftKey || e.ctrlKey || e.metaKey) {
    toggleSelection(index)
    return
  }

  // A plain click on an unselected card selects just it; then drag moves it.
  if (!selected.value.has(index)) {
    setSelection([index])
  }
  draggingIndex = index
  suspend() // collapse the whole drag into one undo step
  ;(e.currentTarget as HTMLElement | null)?.setPointerCapture(e.pointerId)
  e.preventDefault()
}

function onDrag(e: PointerEvent) {
  const slot = slots.value[draggingIndex]
  if (!slot || !canvasRef.value) {
    return
  }
  const rect = canvasRef.value.getBoundingClientRect()
  const x = ((e.clientX - rect.left) / rect.width) * 100
  const y = ((e.clientY - rect.top) / rect.height) * 100
  slot.x = snapVal(x)
  slot.y = snapVal(y)
}

function endDrag(e?: PointerEvent) {
  e?.stopPropagation() // keep the background pointerup (deselect) from firing
  if (draggingIndex !== -1) {
    draggingIndex = -1
    resume()
    record()
  }
}

function rotate(index: number, delta: number) {
  const slot = slots.value[index]
  if (!slot) {
    return
  }
  let r = (slot.rotation + delta) % 360
  if (r < 0) {
    r += 360
  }
  slot.rotation = r
}

function openEdit(index: number) {
  editIndex.value = index
}
function closeEdit() {
  editIndex.value = null
}

// Escape hatches for callers that need to drive the canvas imperatively.
defineExpose({ resetHistory, clearSelection, setSelection })
</script>

<template>
  <div class="spread-canvas-editor">
    <!-- Tray of unplaced cards -->
    <div class="field">
      <span :id="trayCaptionId" class="label"
        >Unplaced Cards <span class="has-text-grey is-size-7">{{ trayHint }}</span></span
      >
      <div class="spread-token-tray" role="group" :aria-labelledby="trayCaptionId">
        <button
          v-for="{ slot, i } in unplacedSlots"
          :key="'tray-' + i"
          type="button"
          class="spread-token"
          :title="'Place card ' + (i + 1)"
          @click="placeFromTray(i)"
        >
          <slot name="tray-token" :item="slot" :index="i"
            ><span>{{ i + 1 }}</span></slot
          >
        </button>
        <span v-if="unplacedIndexes.length === 0" class="has-text-grey is-align-self-center"
          >All cards placed.</span
        >
      </div>
    </div>

    <!-- Full-width layout canvas -->
    <div class="field">
      <!-- Layout toolbar: snap, undo/redo, center, align, distribute, zoom -->
      <div class="spread-tools is-flex is-flex-wrap-wrap is-align-items-center mb-2">
        <Tooltip text="Drag to move · shift-click to multi-select">
          <span class="label mb-0 mr-1">Layout</span>
        </Tooltip>
        <span class="has-text-grey is-size-7 spread-tools-hint">{{ selectedCount }} selected</span>

        <ToggleSwitch v-model="snapToGrid" compact>Snap to grid</ToggleSwitch>

        <span class="spread-tool-sep" aria-hidden="true"></span>

        <ToolbarRoot class="spread-toolbar" aria-label="Layout tools">
          <Tooltip text="Undo">
            <ToolbarButton
              class="button is-small"
              :disabled="!canUndo"
              aria-label="Undo"
              @click="undo"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Redo">
            <ToolbarButton
              class="button is-small"
              :disabled="!canRedo"
              aria-label="Redo"
              @click="redo"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']"
              /></span>
            </ToolbarButton>
          </Tooltip>

          <ToolbarSeparator class="spread-tool-sep" />

          <Tooltip text="Center all cards in the panel (keeps spacing)">
            <ToolbarButton
              class="button is-small"
              :disabled="placedIndexes.length === 0"
              @click="centerAll"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-to-dot']"
              /></span>
              <span>Center</span>
            </ToolbarButton>
          </Tooltip>

          <ToolbarSeparator class="spread-tool-sep" />

          <span class="spread-tool-label">Align</span>
          <Tooltip text="Align left edges">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 2"
              aria-label="Align left edges"
              @click="align('left')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-left']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Align horizontal centers">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 2"
              aria-label="Align horizontal centers"
              @click="align('hcenter')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-center']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Align right edges">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 2"
              aria-label="Align right edges"
              @click="align('right')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-right']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Align top edges">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 2"
              aria-label="Align top edges"
              @click="align('top')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-up']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Align to same height (vertical centers)">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 2"
              aria-label="Align to same height (vertical centers)"
              @click="align('vmiddle')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['bars']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Align bottom edges">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 2"
              aria-label="Align bottom edges"
              @click="align('bottom')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-down']"
              /></span>
            </ToolbarButton>
          </Tooltip>

          <ToolbarSeparator class="spread-tool-sep" />

          <span class="spread-tool-label">Distribute</span>
          <Tooltip text="Equal horizontal spacing">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 3"
              aria-label="Equal horizontal spacing"
              @click="distribute('h')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-left-right']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Equal vertical spacing">
            <ToolbarButton
              class="button is-small"
              :disabled="selectedCount < 3"
              aria-label="Equal vertical spacing"
              @click="distribute('v')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-up-down']"
              /></span>
            </ToolbarButton>
          </Tooltip>

          <Tooltip text="Zoom out">
            <ToolbarButton
              class="button is-small ml-auto"
              :disabled="zoom <= MIN_ZOOM"
              aria-label="Zoom out"
              @click="zoomOut"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-minus']"
              /></span>
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Reset zoom">
            <ToolbarButton class="button is-small zoom-reset" @click="resetZoom">
              {{ Math.round(zoom * 100) }}%
            </ToolbarButton>
          </Tooltip>
          <Tooltip text="Zoom in">
            <ToolbarButton
              class="button is-small"
              :disabled="zoom >= MAX_ZOOM"
              aria-label="Zoom in"
              @click="zoomIn"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-plus']"
              /></span>
            </ToolbarButton>
          </Tooltip>
        </ToolbarRoot>
      </div>

      <div
        :ref="setViewport"
        class="spread-canvas-viewport"
        :class="{ 'is-zoomed': zoom > 1 }"
        :style="viewportStyle"
        @pointerdown="onPanStart"
        @pointermove="onPanMove"
        @pointerup="onCanvasPointerUp"
        @wheel="onWheel"
      >
        <div
          ref="canvasRef"
          class="spread-canvas spread-canvas--zoomable"
          :class="{ 'has-grid': snapToGrid }"
          :style="canvasStyle"
        >
          <template v-for="{ slot, i } in placedSlots" :key="'card-' + i">
            <ContextMenuRoot :modal="false" @update:open="(o: boolean) => o && setSelection([i])">
              <ContextMenuTrigger as-child>
                <div
                  class="spread-card spread-card--editor"
                  :class="{ 'is-selected': isSelected(i) }"
                  :style="{
                    left: slot.x + '%',
                    top: slot.y + '%',
                    '--rotation': slot.rotation + 'deg',
                  }"
                  @pointerdown="onCardPointerDown($event, i)"
                  @pointermove="onDrag"
                  @pointerup="endDrag"
                >
                  <span class="spread-order-badge">{{ i + 1 }}</span>
                  <slot name="card" :item="slot" :index="i" />
                </div>
              </ContextMenuTrigger>
              <ContextMenuPortal>
                <ContextMenuContent class="myst-menu">
                  <ContextMenuItem class="myst-menu-item" @select="rotate(i, -15)">
                    <span class="mi-icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']"
                    /></span>
                    <span>Rotate left</span>
                  </ContextMenuItem>
                  <ContextMenuItem class="myst-menu-item" @select="rotate(i, 15)">
                    <span class="mi-icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']"
                    /></span>
                    <span>Rotate right</span>
                  </ContextMenuItem>
                  <slot name="context-menu-extra" :item="slot" :index="i" />
                  <ContextMenuItem class="myst-menu-item" @select="openEdit(i)">
                    <span class="mi-icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                    /></span>
                    <span>{{ editLabel }}</span>
                  </ContextMenuItem>
                  <ContextMenuSeparator class="myst-menu-sep" />
                  <ContextMenuItem
                    class="myst-menu-item myst-menu-item--danger"
                    @select="unplace(i)"
                  >
                    <span class="mi-icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']"
                    /></span>
                    <span>Remove from layout</span>
                  </ContextMenuItem>
                </ContextMenuContent>
              </ContextMenuPortal>
            </ContextMenuRoot>

            <!-- Floating controls for a single selected card -->
            <div
              v-if="selectedCount === 1 && isSelected(i)"
              class="card-controls"
              :style="{ left: slot.x + '%', top: slot.y - CARD_HALF_H + '%' }"
              @pointerdown.stop
              @pointerup.stop
            >
              <Tooltip text="Rotate left">
                <button
                  type="button"
                  class="button is-small"
                  tabindex="-1"
                  aria-label="Rotate left"
                  @click.stop="rotate(i, -15)"
                >
                  <span class="icon is-small"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']"
                  /></span>
                </button>
              </Tooltip>
              <Tooltip text="Rotate right">
                <button
                  type="button"
                  class="button is-small"
                  tabindex="-1"
                  aria-label="Rotate right"
                  @click.stop="rotate(i, 15)"
                >
                  <span class="icon is-small"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']"
                  /></span>
                </button>
              </Tooltip>
              <slot name="card-controls-extra" :item="slot" :index="i" />
              <Tooltip :text="editLabel">
                <button
                  type="button"
                  class="button is-small is-info"
                  tabindex="-1"
                  :aria-label="editLabel"
                  @click.stop="openEdit(i)"
                >
                  <span class="icon is-small"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                  /></span>
                </button>
              </Tooltip>
              <Tooltip text="Remove from layout">
                <button
                  type="button"
                  class="button is-small is-danger"
                  tabindex="-1"
                  aria-label="Remove from layout"
                  @click.stop="unplace(i)"
                >
                  <span class="icon is-small"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']"
                  /></span>
                </button>
              </Tooltip>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Caller-owned edit modal; this component owns editIndex + the undo coupling. -->
    <slot
      name="edit-modal"
      :item="editIndex !== null ? (slots[editIndex] ?? null) : null"
      :index="editIndex"
      :close="closeEdit"
    />
  </div>
</template>

<style scoped>
.zoom-reset {
  min-width: 3.5rem;
}

/* One continuous toolbar bar: a single rounded surface holding flat icon buttons
   + the snap toggle, instead of a row of separate button pills. */
.spread-tools {
  gap: 0.12rem;
  padding: 5px 9px;
  background: var(--myst-surface-2);
  border: 1px solid var(--myst-border-strong);
  border-radius: 11px;
}

.spread-tools > .label {
  font-size: 0.85rem;
  color: var(--myst-text);
  margin-right: 0.2rem;
}

.spread-tools-hint {
  min-width: 4.5rem;
  margin-right: 0.15rem;
}

/* Flat icon buttons that sit ON the bar (drop Bulma's individual pill look). */
.spread-tools :deep(.button) {
  background: transparent;
  border-color: transparent;
  box-shadow: none;
  color: var(--myst-text-muted);
}

.spread-tools :deep(.button:hover:not([disabled])) {
  background: var(--myst-surface-3);
  color: var(--myst-gold-bright);
  border-color: transparent;
}

.spread-tools :deep(.button:focus-visible) {
  background: var(--myst-surface-3);
  color: var(--myst-gold-bright);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--myst-gold) 45%, transparent);
}

.spread-tools :deep(.button[disabled]) {
  background: transparent;
  border-color: transparent;
  opacity: 0.35;
}

.spread-tool-sep {
  align-self: center;
  width: 1px;
  height: 1.4rem;
  background: var(--myst-border-strong);
  margin: 0 0.25rem;
}

.spread-tool-label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
  color: var(--myst-text-dim);
  padding: 0 0.15rem 0 0.1rem;
}

/* The Reka toolbar (pure ToolbarButtons + separators) fills the remaining bar
   width so the zoom group's `ml-auto` still parks it on the right. */
.spread-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.1rem;
  flex: 1 1 auto;
  outline: none;
}

.spread-toolbar:focus-visible {
  outline: 2px solid var(--myst-gold-bright);
  outline-offset: 2px;
  border-radius: 8px;
}

/* Floating control bar above the selected card; stays upright (not rotated).
   `:slotted` also sizes any caller-provided extra buttons (e.g. reversed). */
.card-controls {
  position: absolute;
  transform: translate(-50%, -100%);
  margin-top: -0.35rem;
  z-index: 6;
  display: flex;
  gap: 0.2rem;
  padding: 0.25rem;
  border-radius: 8px;
  background: var(--myst-surface);
  border: 1px solid var(--myst-border-strong);
  box-shadow: 0 6px 16px rgb(0 0 0 / 50%);
}

.card-controls .button,
.card-controls :slotted(.button) {
  height: 1.9rem;
  width: 1.9rem;
  padding: 0;
}
</style>
