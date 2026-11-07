import { ref, computed, type CSSProperties } from 'vue'

/**
 * Google-Maps-style pan/zoom for the spread-layout editors.
 *
 * The canvas keeps a 0–100% coordinate space; this composable only changes how
 * it is *displayed*, inside an `overflow: hidden` viewport. Zooming changes the
 * canvas's rendered *size* (width %), so content re-rasterizes at full
 * resolution and stays crisp; panning is a cheap `translate` and is done by
 * dragging the background (no scrollbars). Zooming keeps the focal point fixed.
 *
 * Because the canvas is only resized + translated (never bitmap-scaled),
 * pointer→percentage math that reads `canvas.getBoundingClientRect()` (used for
 * dragging cards) stays correct without any extra adjustment.
 */
export function usePanZoom(opts?: { min?: number; max?: number; step?: number }) {
  const MIN_ZOOM = opts?.min ?? 1
  const MAX_ZOOM = opts?.max ?? 3
  const ZOOM_STEP = opts?.step ?? 0.25

  const zoom = ref(1)
  const panX = ref(0)
  const panY = ref(0)
  const viewportRef = ref<HTMLElement | null>(null)

  /** Keep the (scaled) canvas covering the viewport — no empty gutters. */
  function clampPan() {
    const el = viewportRef.value
    if (!el) {
      panX.value = 0
      panY.value = 0
      return
    }
    const minX = el.clientWidth * (1 - zoom.value)
    const minY = el.clientHeight * (1 - zoom.value)
    panX.value = Math.min(0, Math.max(minX, panX.value))
    panY.value = Math.min(0, Math.max(minY, panY.value))
  }

  /** Set zoom, keeping the point under (originX, originY) fixed on screen. */
  function setZoom(next: number, originX?: number, originY?: number) {
    const prev = zoom.value
    const z = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(next * 100) / 100))
    const el = viewportRef.value
    if (el && z !== prev) {
      const ox = originX ?? el.clientWidth / 2
      const oy = originY ?? el.clientHeight / 2
      // Content coord under the focal point must stay put across the change.
      panX.value = ox - ((ox - panX.value) / prev) * z
      panY.value = oy - ((oy - panY.value) / prev) * z
    }
    zoom.value = z
    clampPan()
  }

  function zoomIn() {
    setZoom(zoom.value + ZOOM_STEP)
  }
  function zoomOut() {
    setZoom(zoom.value - ZOOM_STEP)
  }
  function resetZoom() {
    zoom.value = 1
    panX.value = 0
    panY.value = 0
  }

  // ── Drag-to-pan ──────────────────────────────────────────
  let panning = false
  let moved = false
  let startX = 0
  let startY = 0
  let startPanX = 0
  let startPanY = 0

  function onPanStart(e: PointerEvent) {
    panning = true
    moved = false
    startX = e.clientX
    startY = e.clientY
    startPanX = panX.value
    startPanY = panY.value
    ;(e.currentTarget as HTMLElement | null)?.setPointerCapture(e.pointerId)
  }

  function onPanMove(e: PointerEvent) {
    if (!panning) {
      return
    }
    const dx = e.clientX - startX
    const dy = e.clientY - startY
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
      moved = true
    }
    panX.value = startPanX + dx
    panY.value = startPanY + dy
    clampPan()
  }

  function onPanEnd(e: PointerEvent) {
    panning = false
    ;(e.currentTarget as HTMLElement | null)?.releasePointerCapture(e.pointerId)
  }

  function onWheel(e: WheelEvent) {
    e.preventDefault()
    const el = viewportRef.value
    let ox: number | undefined
    let oy: number | undefined
    if (el) {
      const rect = el.getBoundingClientRect()
      ox = e.clientX - rect.left
      oy = e.clientY - rect.top
    }
    setZoom(zoom.value + (e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP), ox, oy)
  }

  /** True when the last pointer interaction was a drag (not a click). */
  function wasDragged() {
    return moved
  }

  // Zoom by changing the canvas's actual *size* (so the browser re-lays-out
  // and re-rasterizes at full resolution — crisp), and only *translate* for
  // panning (which composites cheaply and never blurs). Using `scale()` here
  // instead would GPU-upscale a single 100% bitmap and look blurry.
  const canvasStyle = computed<CSSProperties>(() => ({
    width: `${zoom.value * 100}%`,
    transform: `translate(${panX.value}px, ${panY.value}px)`,
  }))

  return {
    zoom,
    panX,
    panY,
    viewportRef,
    MIN_ZOOM,
    MAX_ZOOM,
    zoomIn,
    zoomOut,
    resetZoom,
    setZoom,
    onPanStart,
    onPanMove,
    onPanEnd,
    onWheel,
    clampPan,
    wasDragged,
    canvasStyle,
  }
}
