import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import { useLayoutTools, type XYPoint } from '@/composables/useLayoutTools'

/** Build a tools instance over a fresh slot array; placed = all indices. */
function setup(slots: XYPoint[], snap = false, snapStep = 2.5) {
  const snapToGrid = ref(snap)
  const placed = () => slots.map((_, i) => i)
  const tools = useLayoutTools(slots, placed, snapToGrid, snapStep)
  return { tools, snapToGrid }
}

describe('useLayoutTools — selection', () => {
  it('sets, toggles, clears and reports selection', () => {
    const { tools } = setup([
      { x: 0, y: 0 },
      { x: 0, y: 0 },
      { x: 0, y: 0 },
    ])

    tools.setSelection([0, 2])
    expect(tools.selectedCount.value).toBe(2)
    expect(tools.isSelected(0)).toBe(true)
    expect(tools.isSelected(1)).toBe(false)

    tools.toggleSelection(1)
    expect(tools.isSelected(1)).toBe(true)
    tools.toggleSelection(0)
    expect(tools.isSelected(0)).toBe(false)

    tools.clearSelection()
    expect(tools.selectedCount.value).toBe(0)
  })

  it('prunes out-of-range indices after a shrink', () => {
    const { tools } = setup([
      { x: 0, y: 0 },
      { x: 0, y: 0 },
      { x: 0, y: 0 },
    ])
    tools.setSelection([0, 1, 2])
    tools.pruneSelection(2) // only indices < 2 remain valid
    expect([...tools.selected.value].sort()).toEqual([0, 1])
  })
})

describe('useLayoutTools — snapVal', () => {
  it('rounds to 2 decimals and clamps to 0–100 when snapping is off', () => {
    const { tools } = setup([])
    expect(tools.snapVal(33.3333)).toBe(33.33)
    expect(tools.snapVal(-5)).toBe(0)
    expect(tools.snapVal(150)).toBe(100)
  })

  it('snaps to the nearest step when snapping is on', () => {
    const { tools } = setup([], true, 2.5)
    expect(tools.snapVal(11)).toBe(10) // nearest 2.5 multiple
    expect(tools.snapVal(13.7)).toBe(12.5)
  })
})

describe('useLayoutTools — centerAll', () => {
  it('recenters the bounding box on (50,50) preserving relative spacing', () => {
    const slots = [
      { x: 10, y: 10 },
      { x: 30, y: 20 },
    ]
    const { tools } = setup(slots)
    tools.centerAll()

    // bbox was x:10–30 (center 20), y:10–20 (center 15) → shift +30 x, +35 y.
    expect(slots[0]).toEqual({ x: 40, y: 45 })
    expect(slots[1]).toEqual({ x: 60, y: 55 })
    // Relative spacing preserved.
    expect(slots[1]!.x - slots[0]!.x).toBe(20)
    expect(slots[1]!.y - slots[0]!.y).toBe(10)
  })
})

describe('useLayoutTools — align', () => {
  it('does nothing with fewer than two selected', () => {
    const slots = [
      { x: 10, y: 10 },
      { x: 30, y: 40 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0])
    tools.align('left')
    expect(slots[0]!.x).toBe(10) // unchanged
  })

  it('aligns left edges to the minimum x', () => {
    const slots = [
      { x: 10, y: 0 },
      { x: 30, y: 0 },
      { x: 50, y: 0 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1, 2])
    tools.align('left')
    expect(slots.map((s) => s.x)).toEqual([10, 10, 10])
  })

  it('aligns to horizontal center (midpoint of extremes)', () => {
    const slots = [
      { x: 10, y: 0 },
      { x: 50, y: 0 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1])
    tools.align('hcenter')
    expect(slots.map((s) => s.x)).toEqual([30, 30])
  })

  it('aligns bottom edges to the maximum y', () => {
    const slots = [
      { x: 0, y: 10 },
      { x: 0, y: 80 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1])
    tools.align('bottom')
    expect(slots.map((s) => s.y)).toEqual([80, 80])
  })
})

describe('useLayoutTools — distribute (V-shape grouping fix)', () => {
  it('does nothing with fewer than three selected', () => {
    const slots = [
      { x: 0, y: 0 },
      { x: 0, y: 50 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1])
    tools.distribute('v')
    expect(slots.map((s) => s.y)).toEqual([0, 50])
  })

  it('spaces three distinct rows evenly on the vertical axis', () => {
    const slots = [
      { x: 0, y: 0 },
      { x: 0, y: 40 },
      { x: 0, y: 60 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1, 2])
    tools.distribute('v')
    // 0 → 0, middle → 30, last → 60 (even step of 30).
    expect(slots.map((s) => s.y)).toEqual([0, 30, 60])
  })

  it('keeps symmetric pairs together instead of pulling them apart', () => {
    // A V: two cards share each row (y), one apex card between them.
    const slots = [
      { x: 40, y: 0 },
      { x: 60, y: 0 }, // top row pair
      { x: 50, y: 5 }, // apex (its own group)
      { x: 40, y: 30 },
      { x: 60, y: 30 }, // bottom row pair
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1, 2, 3, 4])
    tools.distribute('v')

    // Three groups (y≈0, y≈5, y≈30) distributed between 0 and 30 → 0, 15, 30.
    expect(slots[0]!.y).toBe(0)
    expect(slots[1]!.y).toBe(0) // pair stays matched, NOT split to 7.5
    expect(slots[2]!.y).toBe(15) // apex
    expect(slots[3]!.y).toBe(30)
    expect(slots[4]!.y).toBe(30) // pair stays matched
    // x values are untouched by a vertical distribute.
    expect(slots.map((s) => s.x)).toEqual([40, 60, 50, 40, 60])
  })

  it('groups cards within ~1% tolerance as one row', () => {
    const slots = [
      { x: 0, y: 0 },
      { x: 0, y: 0.5 }, // within tolerance → one group
      { x: 0, y: 20 },
      { x: 0, y: 40 },
    ]
    const { tools } = setup(slots)
    tools.setSelection([0, 1, 2, 3])
    tools.distribute('v')
    // Groups: {0,0.5}, {20}, {40} → distributed 0, 20, 40.
    expect(slots[0]!.y).toBe(0)
    expect(slots[1]!.y).toBe(0)
    expect(slots[2]!.y).toBe(20)
    expect(slots[3]!.y).toBe(40)
  })
})
