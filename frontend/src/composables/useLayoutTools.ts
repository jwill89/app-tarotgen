import { ref, computed, toValue, type MaybeRefOrGetter } from 'vue'

/**
 * Shared selection + alignment/distribution/centering tools for the spread
 * layout editors. Operates on any reactive array of objects carrying `x`/`y`
 * percentages (0–100); mutations are applied in place so the host component's
 * reactivity drives the canvas.
 */

export interface XYPoint {
  x: number
  y: number
}

export type AlignEdge = 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom'

export function useLayoutTools(
  slots: XYPoint[],
  placedIndexes: MaybeRefOrGetter<number[]>,
  snapToGrid: MaybeRefOrGetter<boolean>,
  snapStep = 2.5,
) {
  // ── Multi-selection of placed-card indices ──────────────────
  const selected = ref<Set<number>>(new Set())
  const selectedCount = computed(() => selected.value.size)

  const isSelected = (i: number): boolean => selected.value.has(i)
  const setSelection = (indices: number[]): void => {
    selected.value = new Set(indices)
  }
  const clearSelection = (): void => {
    selected.value = new Set()
  }

  function toggleSelection(i: number): void {
    const next = new Set(selected.value)
    if (next.has(i)) {
      next.delete(i)
    } else {
      next.add(i)
    }
    selected.value = next
  }

  /** Drop any selected indices that are out of range (e.g. after a resize). */
  function pruneSelection(length: number): void {
    selected.value = new Set([...selected.value].filter((i) => i < length))
  }

  // ── Geometry helpers ────────────────────────────────────────
  const clampPct = (v: number): number => Math.max(0, Math.min(100, v))
  const round2 = (v: number): number => Math.round(v * 100) / 100

  function snapVal(v: number): number {
    const c = clampPct(v)
    return toValue(snapToGrid) ? Math.round(c / snapStep) * snapStep : round2(c)
  }

  /** The cards at these indices, skipping any the layout no longer has. */
  function pointsAt(indices: number[]): XYPoint[] {
    return indices.flatMap((i) => slots[i] ?? [])
  }

  /** Center all placed cards in the panel, preserving relative spacing. */
  function centerAll(): void {
    const points = pointsAt(toValue(placedIndexes))
    if (points.length === 0) {
      return
    }
    const xs = points.map((p) => p.x)
    const ys = points.map((p) => p.y)
    const dx = 50 - (Math.min(...xs) + Math.max(...xs)) / 2
    const dy = 50 - (Math.min(...ys) + Math.max(...ys)) / 2
    points.forEach((p) => {
      p.x = round2(clampPct(p.x + dx))
      p.y = round2(clampPct(p.y + dy))
    })
  }

  /** Align selected cards' edges/centers on one axis (needs 2+). */
  function align(edge: AlignEdge): void {
    const points = pointsAt([...selected.value])
    if (points.length < 2) {
      return
    }
    const xs = points.map((p) => p.x)
    const ys = points.map((p) => p.y)

    switch (edge) {
      case 'left': {
        const v = snapVal(Math.min(...xs))
        points.forEach((p) => {
          p.x = v
        })
        break
      }
      case 'right': {
        const v = snapVal(Math.max(...xs))
        points.forEach((p) => {
          p.x = v
        })
        break
      }
      case 'hcenter': {
        const v = round2((Math.min(...xs) + Math.max(...xs)) / 2)
        points.forEach((p) => {
          p.x = v
        })
        break
      }
      case 'top': {
        const v = snapVal(Math.min(...ys))
        points.forEach((p) => {
          p.y = v
        })
        break
      }
      case 'bottom': {
        const v = snapVal(Math.max(...ys))
        points.forEach((p) => {
          p.y = v
        })
        break
      }
      case 'vmiddle': {
        const v = round2((Math.min(...ys) + Math.max(...ys)) / 2)
        points.forEach((p) => {
          p.y = v
        })
        break
      }
    }
  }

  /**
   * Space the selected cards equally between the two extremes (needs 3+).
   * Cards sharing (nearly) the same position on the axis are grouped and moved
   * together, so symmetric arrangements (e.g. the arms of a V) keep their
   * matching positions instead of being pulled apart.
   */
  function distribute(axis: 'h' | 'v'): void {
    const points = pointsAt([...selected.value])
    if (points.length < 3) {
      return
    }

    const coord = (p: XYPoint): number => (axis === 'h' ? p.x : p.y)
    const apply = (p: XYPoint, v: number): void => {
      if (axis === 'h') {
        p.x = v
      } else {
        p.y = v
      }
    }

    const TOLERANCE = 1 // percent within which cards count as one row/column
    const sorted = points.slice().sort((a, b) => coord(a) - coord(b))
    // Each group is led by its first card, which the next one is measured from.
    const groups: { lead: XYPoint; members: XYPoint[] }[] = []
    for (const p of sorted) {
      const last = groups.at(-1)
      if (last && Math.abs(coord(p) - coord(last.lead)) <= TOLERANCE) {
        last.members.push(p)
      } else {
        groups.push({ lead: p, members: [p] })
      }
    }
    const firstGroup = groups[0]
    const lastGroup = groups.at(-1)
    if (groups.length < 2 || !firstGroup || !lastGroup) {
      return
    }

    const first = coord(firstGroup.lead)
    const step = (coord(lastGroup.lead) - first) / (groups.length - 1)
    groups.forEach((group, k) => {
      const v = round2(first + step * k)
      group.members.forEach((p) => apply(p, v))
    })
  }

  return {
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
  }
}
