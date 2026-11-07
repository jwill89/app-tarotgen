import { describe, it, expect } from 'vitest'
import { usePanZoom } from '@/composables/usePanZoom'

/**
 * A viewport of a given size. jsdom does no layout, so clientWidth/Height
 * and getBoundingClientRect are set by hand.
 */
function viewport(width = 400, height = 300, left = 0, top = 0): HTMLElement {
  const el = document.createElement('div')
  Object.defineProperty(el, 'clientWidth', { value: width })
  Object.defineProperty(el, 'clientHeight', { value: height })
  el.getBoundingClientRect = () => new DOMRect(left, top, width, height)
  return el
}

/** Wire the drag handlers to the viewport, as the editors' templates do. */
function withDragging(el: HTMLElement, pz: ReturnType<typeof usePanZoom>) {
  el.addEventListener('pointerdown', (e) => pz.onPanStart(e))
  el.addEventListener('pointermove', (e) => pz.onPanMove(e))
  el.addEventListener('pointerup', (e) => pz.onPanEnd(e))
  const fire = (type: string, clientX: number, clientY: number) =>
    el.dispatchEvent(new PointerEvent(type, { clientX, clientY, pointerId: 1 }))
  return {
    down: (x: number, y: number) => fire('pointerdown', x, y),
    move: (x: number, y: number) => fire('pointermove', x, y),
    up: (x: number, y: number) => fire('pointerup', x, y),
  }
}

/** The content point (in unzoomed px) that sits under a screen point. */
const contentAt = (pz: ReturnType<typeof usePanZoom>, sx: number, sy: number) => ({
  x: (sx - pz.panX.value) / pz.zoom.value,
  y: (sy - pz.panY.value) / pz.zoom.value,
})

describe('usePanZoom zooming', () => {
  it('steps in and out within its limits', () => {
    const pz = usePanZoom()
    pz.viewportRef.value = viewport()

    pz.zoomIn()
    expect(pz.zoom.value).toBe(1.25)
    for (let i = 0; i < 20; i++) {
      pz.zoomIn()
    }
    expect(pz.zoom.value).toBe(pz.MAX_ZOOM)

    for (let i = 0; i < 20; i++) {
      pz.zoomOut()
    }
    expect(pz.zoom.value).toBe(pz.MIN_ZOOM)
  })

  it('takes its limits and step from the caller', () => {
    const pz = usePanZoom({ min: 0.5, max: 2, step: 0.5 })

    pz.zoomOut()
    expect(pz.zoom.value).toBe(0.5)
    pz.setZoom(9)
    expect(pz.zoom.value).toBe(2)
  })

  it('keeps the point under the cursor in place', () => {
    const pz = usePanZoom()
    pz.viewportRef.value = viewport()
    const before = contentAt(pz, 100, 120)

    pz.setZoom(2, 100, 120)

    expect(contentAt(pz, 100, 120)).toEqual(before)
  })

  it('zooms the buttons around the middle of the viewport', () => {
    const pz = usePanZoom()
    pz.viewportRef.value = viewport(400, 300)

    pz.setZoom(2)

    expect(contentAt(pz, 200, 150)).toEqual({ x: 200, y: 150 })
  })

  it('zooms in with the wheel at the cursor, and out the other way', () => {
    const pz = usePanZoom()
    pz.viewportRef.value = viewport(400, 300, 50, 40)
    const wheel = (deltaY: number) =>
      new WheelEvent('wheel', { deltaY, clientX: 150, clientY: 100, cancelable: true })

    const up = wheel(-100)
    pz.onWheel(up)
    expect(up.defaultPrevented).toBe(true) // the page itself must not scroll
    expect(pz.zoom.value).toBe(1.25)
    // The cursor is at (100, 60) inside a viewport whose corner is at (50, 40).
    expect(contentAt(pz, 100, 60)).toEqual({ x: 100, y: 60 })

    pz.onWheel(wheel(100))
    expect(pz.zoom.value).toBe(1)
  })

  it('rounds to whole percent so repeated steps do not drift', () => {
    const pz = usePanZoom()
    pz.setZoom(1.23456)
    expect(pz.zoom.value).toBe(1.23)
  })

  it('sizes the canvas rather than scaling it, so the art stays sharp', () => {
    const pz = usePanZoom()
    pz.viewportRef.value = viewport()

    pz.setZoom(2, 0, 0)
    pz.panX.value = -40
    pz.panY.value = -30

    expect(pz.canvasStyle.value).toEqual({
      width: '200%',
      transform: 'translate(-40px, -30px)',
    })
  })

  it('goes back to the whole canvas on reset', () => {
    const pz = usePanZoom()
    pz.viewportRef.value = viewport()
    pz.setZoom(2.5, 300, 200)

    pz.resetZoom()

    expect([pz.zoom.value, pz.panX.value, pz.panY.value]).toEqual([1, 0, 0])
  })
})

describe('usePanZoom panning', () => {
  it('drags the zoomed canvas with the pointer', () => {
    const pz = usePanZoom()
    const el = viewport(400, 300)
    pz.viewportRef.value = el
    pz.setZoom(2, 0, 0)
    const drag = withDragging(el, pz)

    drag.down(200, 150)
    // The viewport keeps the pointer while dragging, even off its edge.
    expect(el.hasPointerCapture(1)).toBe(true)
    drag.move(150, 120)
    drag.up(150, 120)

    expect([pz.panX.value, pz.panY.value]).toEqual([-50, -30])
    expect(pz.wasDragged()).toBe(true)
    expect(el.hasPointerCapture(1)).toBe(false)
  })

  it('never lets the canvas leave an empty gutter', () => {
    const pz = usePanZoom()
    const el = viewport(400, 300)
    pz.viewportRef.value = el
    pz.setZoom(2, 0, 0)
    const drag = withDragging(el, pz)

    drag.down(200, 150)
    drag.move(400, 400) // past the top-left edge
    expect([pz.panX.value, pz.panY.value]).toEqual([0, 0])

    drag.move(-1000, -1000) // past the bottom-right edge
    // At 2x the canvas is 800x600, so it can move at most 400x300.
    expect([pz.panX.value, pz.panY.value]).toEqual([-400, -300])
  })

  it('cannot pan an unzoomed canvas', () => {
    const pz = usePanZoom()
    const el = viewport()
    pz.viewportRef.value = el
    const drag = withDragging(el, pz)

    drag.down(200, 150)
    drag.move(100, 50)

    expect([pz.panX.value, pz.panY.value]).toEqual([0, 0])
  })

  it('treats a press that barely moves as a click, not a drag', () => {
    const pz = usePanZoom()
    const el = viewport()
    pz.viewportRef.value = el
    const drag = withDragging(el, pz)

    drag.down(200, 150)
    drag.move(202, 151)
    drag.up(202, 151)

    expect(pz.wasDragged()).toBe(false)
  })

  it('ignores pointer movement when no drag has started', () => {
    const pz = usePanZoom()
    const el = viewport()
    pz.viewportRef.value = el
    pz.setZoom(2, 0, 0)
    const drag = withDragging(el, pz)

    drag.move(10, 10)

    expect([pz.panX.value, pz.panY.value]).toEqual([0, 0])
    expect(pz.wasDragged()).toBe(false)
  })

  it('pins the canvas when there is no viewport to measure', () => {
    const pz = usePanZoom()
    pz.panX.value = -20
    pz.panY.value = -10

    pz.clampPan()

    expect([pz.panX.value, pz.panY.value]).toEqual([0, 0])
  })
})
