<script setup lang="ts">
/**
 * A spread's layout, read-only: card outlines placed as the spread places them,
 * zoomed to fill the square canvas (useSpreadLayout, as the reading page fits
 * its cards). New Draw previews the chosen spread with it, and the spread
 * library shows each spread's shape.
 *
 * Each card carries its position number where a later (on-top) card doesn't
 * cover it. `thumb` draws a small silhouette instead: no numbers, no grid.
 * The root takes the caller's class and style (e.g. cardAspectStyle(deck)).
 */
import { computed } from 'vue'
import type { SpreadPosition } from '@/types'
import { useSpreadLayout } from '@/composables/useSpreadLayout'

const props = defineProps<{
  positions: SpreadPosition[]
  /** A small silhouette: no position numbers, no grid. */
  thumb?: boolean
}>()

const sorted = computed(() => [...props.positions].sort((a, b) => a.order - b.order))

const { cardSize, project } = useSpreadLayout(sorted)

interface PreviewCard {
  order: number
  style: Record<string, string>
  numStyle: Record<string, string>
}

// For each card pick a spot for its number that isn't covered by a later
// (on-top) card. The canvas is square, so x/y percentages share one pixel scale
// and plain point-in-rotated-rect math works.
const cards = computed<PreviewCard[]>(() => {
  const { w: cardW, h: cardH }: { w: number; h: number } = cardSize.value
  const toRad = (d: number) => (d * Math.PI) / 180

  const placed = sorted.value.map((p) => {
    const { x, y } = project(p)
    return { order: p.order, rot: p.rotation, gx: x, gy: y }
  })

  // Is global point (px,py) inside card c's rotated rectangle?
  const inside = (px: number, py: number, c: (typeof placed)[number]) => {
    const r = toRad(-c.rot)
    const dx = px - c.gx
    const dy = py - c.gy
    const lx = dx * Math.cos(r) - dy * Math.sin(r)
    const ly = dx * Math.sin(r) + dy * Math.cos(r)
    return Math.abs(lx) <= cardW / 2 && Math.abs(ly) <= cardH / 2
  }

  // Candidate number spots as a fraction of the card's half-size: centre
  // first, then edge midpoints, then corners.
  const centre: [number, number] = [0, 0]
  const candidates: [number, number][] = [
    centre,
    [0, -0.68],
    [0, 0.68],
    [-0.68, 0],
    [0.68, 0],
    [-0.62, -0.62],
    [0.62, -0.62],
    [-0.62, 0.62],
    [0.62, 0.62],
  ]

  return placed.map((c, i) => {
    const onTop = placed.filter((_, j) => j > i)
    let chosen = centre
    for (const [fx, fy] of candidates) {
      const lx = fx * (cardW / 2)
      const ly = fy * (cardH / 2)
      const r = toRad(c.rot)
      const gx = c.gx + (lx * Math.cos(r) - ly * Math.sin(r))
      const gy = c.gy + (lx * Math.sin(r) + ly * Math.cos(r))
      if (!onTop.some((lc) => inside(gx, gy, lc))) {
        chosen = [fx, fy]
        break
      }
    }
    return {
      order: c.order,
      style: {
        left: `${c.gx}%`,
        top: `${c.gy}%`,
        width: `${cardW}%`,
        '--rotation': `${c.rot}deg`,
      },
      numStyle: {
        left: `${50 + chosen[0] * 50}%`,
        top: `${50 + chosen[1] * 50}%`,
      },
    }
  })
})
</script>

<template>
  <div
    class="spread-canvas spread-canvas--preview"
    :class="thumb ? 'spread-canvas--thumb' : 'has-grid'"
    :aria-hidden="thumb ? 'true' : undefined"
  >
    <div
      v-for="card in cards"
      :key="'preview-' + card.order"
      class="spread-card spread-card--editor"
      :style="card.style"
    >
      <span v-if="!thumb" class="preview-number" :style="card.numStyle">{{ card.order }}</span>
    </div>
  </div>
</template>

<style scoped>
/* A silhouette at thumbnail size: finer outlines, no shadow, quieter corners. */
.spread-canvas--thumb {
  border-radius: 10px;
}

.spread-canvas--thumb .spread-card--editor {
  border-width: 1px;
  border-radius: 3px;
  box-shadow: none;
  background-color: var(--myst-surface-3);
}
</style>
