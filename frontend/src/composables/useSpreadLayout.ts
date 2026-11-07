import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import type { SpreadPosition } from '@/types'

/**
 * Geometry for rendering a spread's positions onto the square layout canvas.
 *
 * The canvas is square, so x/y percentages share one pixel scale. A card is a
 * fixed fraction of the canvas width with a tarot aspect ratio. This composable
 * centralises the "fit the bounding box and zoom" math that the reading view and
 * the new-reading preview both need, so the two can't drift apart.
 */

// Card extents as a percentage of the (square) canvas.
export const CARD_WIDTH_PCT = 11
export const CARD_ASPECT = 8.6 / 5 // height / width
const CARD_HALF_W = CARD_WIDTH_PCT / 2
export const CARD_HALF_H = (CARD_WIDTH_PCT * CARD_ASPECT) / 2

export interface SpreadFit {
  scale: number
  cx: number
  cy: number
}

/**
 * Zoom the layout so the cards fill the canvas instead of leaving dead space
 * around small spreads: bound all positions (plus card extents) and scale to
 * fit, clamped to [1, 2.5]. Pure so the image exporter can reuse it.
 */
export function computeSpreadFit(
  positions: SpreadPosition[],
  cardAspect: number = CARD_ASPECT,
): SpreadFit {
  if (positions.length === 0) {
    return { scale: 1, cx: 50, cy: 50 }
  }

  const halfH = (CARD_WIDTH_PCT * cardAspect) / 2
  const xs = positions.map((p) => p.x)
  const ys = positions.map((p) => p.y)
  const minX = Math.min(...xs),
    maxX = Math.max(...xs)
  const minY = Math.min(...ys),
    maxY = Math.max(...ys)
  const bboxW = maxX - minX + CARD_HALF_W * 2
  const bboxH = maxY - minY + halfH * 2
  const scale = Math.max(1, Math.min(100 / bboxW, 100 / bboxH) * 0.92, 1)

  return {
    scale: Math.min(scale, 2.5),
    cx: (minX + maxX) / 2,
    cy: (minY + maxY) / 2,
  }
}

export function useSpreadLayout(source: MaybeRefOrGetter<SpreadPosition[]>) {
  const fit = computed<SpreadFit>(() => computeSpreadFit(toValue(source)))

  /** A card's size in canvas-percent at the current fit. */
  const cardSize = computed(() => {
    const w = CARD_WIDTH_PCT * fit.value.scale
    return { w, h: w * CARD_ASPECT }
  })

  /** Project a position to its on-canvas centre (in canvas-percent). */
  function project(p: Pick<SpreadPosition, 'x' | 'y'>): { x: number; y: number } {
    const { scale, cx, cy } = fit.value
    return { x: 50 + (p.x - cx) * scale, y: 50 + (p.y - cy) * scale }
  }

  /** Inline style positioning a card on the canvas. */
  function cardStyle(p: SpreadPosition): Record<string, string> {
    const { x, y } = project(p)
    const w: number = cardSize.value.w
    return {
      left: `${x}%`,
      top: `${y}%`,
      width: `${w}%`,
      '--rotation': `${p.rotation}deg`,
    }
  }

  return { fit, cardSize, project, cardStyle }
}
