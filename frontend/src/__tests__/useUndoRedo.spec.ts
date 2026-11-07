import { describe, it, expect, vi } from 'vitest'
import { effectScope, nextTick, reactive } from 'vue'
import { useUndoRedo } from '@/composables/useUndoRedo'

interface Slot {
  x: number
  y: number
}

describe('useUndoRedo — history via explicit record()', () => {
  it('starts empty until reset seeds the first entry', () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const h = useUndoRedo(slots)
    expect(h.canUndo.value).toBe(false)
    expect(h.canRedo.value).toBe(false)

    h.reset()
    expect(h.canUndo.value).toBe(false) // single entry, nothing to undo yet
    expect(h.canRedo.value).toBe(false)
  })

  it('records changes and undoes/redoes them', () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const h = useUndoRedo(slots)
    h.reset()

    slots[0]!.x = 10
    h.record()
    slots[0]!.x = 20
    h.record()
    expect(h.canUndo.value).toBe(true)

    h.undo()
    expect(slots[0]!.x).toBe(10)
    h.undo()
    expect(slots[0]!.x).toBe(0)
    expect(h.canUndo.value).toBe(false)

    h.redo()
    expect(slots[0]!.x).toBe(10)
    h.redo()
    expect(slots[0]!.x).toBe(20)
    expect(h.canRedo.value).toBe(false)
  })

  it('drops the redo branch when a new change is recorded after undo', async () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const h = useUndoRedo(slots)
    h.reset()

    slots[0]!.x = 10
    h.record()
    slots[0]!.x = 20
    h.record()
    h.undo() // back to x=10, redo available
    // undo() sets an internal "restoring" guard that clears on the next
    // tick; wait for it so the following record() isn't swallowed.
    await nextTick()
    expect(h.canRedo.value).toBe(true)

    slots[0]!.x = 99
    h.record() // new branch
    expect(h.canRedo.value).toBe(false)
    h.undo()
    expect(slots[0]!.x).toBe(10)
  })

  it('ignores no-op records (identical snapshots)', () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const h = useUndoRedo(slots)
    h.reset()
    h.record() // nothing changed
    expect(h.canUndo.value).toBe(false)
  })

  it('does not record while suspended', () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const h = useUndoRedo(slots)
    h.reset()

    h.suspend()
    slots[0]!.x = 1
    h.record()
    slots[0]!.x = 2
    h.record()
    expect(h.canUndo.value).toBe(false)

    h.resume()
    slots[0]!.x = 3
    h.record()
    expect(h.canUndo.value).toBe(true)
    h.undo()
    // Collapsed: the suspended intermediate states were never captured.
    expect(slots[0]!.x).toBe(0)
  })

  it('invokes the onRestore callback on undo/redo', () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const onRestore = vi.fn()
    const h = useUndoRedo(slots, onRestore)
    h.reset()
    slots[0]!.x = 5
    h.record()

    h.undo()
    h.redo()
    expect(onRestore).toHaveBeenCalledTimes(2)
  })

  it('bounds the stack to maxEntries', () => {
    const slots: Slot[] = [{ x: 0, y: 0 }]
    const h = useUndoRedo(slots, undefined, 3) // tiny cap
    h.reset()
    for (let i = 1; i <= 10; i++) {
      slots[0]!.x = i
      h.record()
    }

    // Undo as far as possible; capped history means we can't reach x=0 again.
    let guard = 0
    while (h.canUndo.value && guard++ < 50) {
      h.undo()
    }
    expect(slots[0]!.x).toBeGreaterThan(0)
  })
})

describe('useUndoRedo — deep watcher', () => {
  it('captures a mutation automatically on the next flush', async () => {
    // The deep watcher only tracks a reactive array (as the editor views
    // pass it); a plain array wouldn't trigger reactivity.
    const slots = reactive<Slot[]>([{ x: 0, y: 0 }])
    const scope = effectScope()
    const h = scope.run(() => useUndoRedo(slots))!
    h.reset()

    slots[0]!.x = 42
    await nextTick()
    expect(h.canUndo.value).toBe(true)
    h.undo()
    expect(slots[0]!.x).toBe(0)

    scope.stop()
  })
})
