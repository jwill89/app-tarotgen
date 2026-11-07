import { ref } from 'vue'
import type { SpreadPosition } from '@/types'
import { computeSpreadFit, CARD_WIDTH_PCT, CARD_ASPECT } from '@/composables/useSpreadLayout'
import { useToasts } from '@/composables/useToasts'

/**
 * Renders a reading to a high-resolution PNG and triggers a download.
 *
 * Unlike a screenshot of the on-screen canvas, this draws the **full-resolution**
 * card images onto a large offscreen canvas, so the exported cards are big and
 * readable. Spread readings keep their layout (rotation/reversal preserved) with
 * a numbered legend; free-draw readings render as a captioned grid.
 */

interface ExportCard {
  imgUrl: string
  /** Tried when imgUrl doesn't load (a deck whose web images aren't generated yet). */
  fallbackUrl?: string
  reversed: boolean
  card_name: string
}

export interface ExportOptions {
  fileName: string
  readingId: string
  title: string
  subtitle: string
  cards: ExportCard[]
  positions?: SpreadPosition[] | null
  /**
   * Output resolution multiplier. 1 = standard (~1600px wide); 2 = high-res
   * (~3200px wide) for sharper, larger cards. The layout is identical — only
   * the backing-store pixel density changes.
   */
  scale?: number
  /**
   * Card height ÷ width for this deck (defaults to the standard tarot ratio).
   * Keeps exported cards in the deck's true proportions.
   */
  cardAspect?: number
}

// Canvas geometry (device pixels). Generous sizes keep card art crisp.
const WIDTH = 1600
const PAD = 72
const HEADER_H = 190
const FOOTER_H = 80
const RADIUS = 18

const BG_TOP = '#101016'
const BG_BOTTOM = '#0b0b0f'
const GOLD = '#c9a24b'
const GOLD_LINE = 'rgba(201, 162, 75, 0.35)' // hairline tint of GOLD (was old-gold #e9c46a)
const PANEL = '#1c1c25' // opaque panel behind text — matches --myst-surface-2
const CARD_PLACEHOLDER = '#24242f' // empty card slot — matches --myst-surface-3
const TEXT = '#ecebf2'
const MUTED = 'rgba(236, 235, 242, 0.6)'

function loadImage(url: string): Promise<HTMLImageElement | null> {
  return new Promise((resolve) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => resolve(null)
    img.src = url
  })
}

async function loadCardImage(card: ExportCard): Promise<HTMLImageElement | null> {
  const img = await loadImage(card.imgUrl)
  if (img || !card.fallbackUrl) {
    return img
  }
  return loadImage(card.fallbackUrl)
}

function roundRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
): void {
  const radius = Math.min(r, w / 2, h / 2)
  ctx.beginPath()
  ctx.moveTo(x + radius, y)
  ctx.arcTo(x + w, y, x + w, y + h, radius)
  ctx.arcTo(x + w, y + h, x, y + h, radius)
  ctx.arcTo(x, y + h, x, y, radius)
  ctx.arcTo(x, y, x + w, y, radius)
  ctx.closePath()
}

function drawImageCover(
  ctx: CanvasRenderingContext2D,
  img: HTMLImageElement,
  x: number,
  y: number,
  w: number,
  h: number,
): void {
  const target = w / h
  const source = img.width / img.height
  let sx = 0,
    sy = 0,
    sw = img.width,
    sh = img.height
  if (source > target) {
    sw = img.height * target
    sx = (img.width - sw) / 2
  } else {
    sh = img.width / target
    sy = (img.height - sh) / 2
  }
  ctx.drawImage(img, sx, sy, sw, sh, x, y, w, h)
}

function drawCard(
  ctx: CanvasRenderingContext2D,
  img: HTMLImageElement | null,
  cx: number,
  cy: number,
  w: number,
  h: number,
  rotationDeg: number,
  reversed: boolean,
): void {
  ctx.save()
  ctx.translate(cx, cy)
  // A reversed card is a 180° turn of the art (matches the on-screen flip).
  ctx.rotate(((rotationDeg + (reversed ? 180 : 0)) * Math.PI) / 180)
  ctx.shadowColor = 'rgba(0, 0, 0, 0.5)'
  ctx.shadowBlur = 18
  ctx.shadowOffsetY = 8
  roundRect(ctx, -w / 2, -h / 2, w, h, RADIUS)
  if (img) {
    ctx.save()
    ctx.clip()
    ctx.shadowColor = 'transparent'
    drawImageCover(ctx, img, -w / 2, -h / 2, w, h)
    ctx.restore()
  } else {
    ctx.fillStyle = CARD_PLACEHOLDER
    ctx.fill()
  }
  ctx.shadowColor = 'transparent'
  ctx.lineWidth = 3
  ctx.strokeStyle = GOLD_LINE
  roundRect(ctx, -w / 2, -h / 2, w, h, RADIUS)
  ctx.stroke()
  ctx.restore()
}

/**
 * An OPAQUE rounded panel that sits behind text.
 */
function drawPanel(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  border = true,
): void {
  ctx.save()
  roundRect(ctx, x, y, w, h, 16)
  ctx.fillStyle = PANEL
  ctx.fill()
  if (border) {
    ctx.lineWidth = 1.5
    ctx.strokeStyle = 'rgba(201, 162, 75, 0.18)'
    ctx.stroke()
  }
  ctx.restore()
}

function drawHeader(ctx: CanvasRenderingContext2D, title: string, subtitle: string): void {
  drawPanel(ctx, PAD, 22, WIDTH - PAD * 2, HEADER_H - 44)

  ctx.textAlign = 'center'
  ctx.fillStyle = GOLD
  ctx.font = '700 58px Georgia, "Times New Roman", serif'
  ctx.fillText('✦ ' + title + ' ✦', WIDTH / 2, 92, WIDTH - PAD * 2 - 40)

  ctx.fillStyle = MUTED
  ctx.font = '400 30px Georgia, serif'
  ctx.fillText(subtitle, WIDTH / 2, 142, WIDTH - PAD * 2 - 40)
}

function drawFooter(ctx: CanvasRenderingContext2D, y: number, readingId: string): void {
  ctx.textAlign = 'center'
  ctx.fillStyle = MUTED
  ctx.font = '400 26px Georgia, serif'
  ctx.fillText('tarotgen.io   ·   Reading ' + readingId, WIDTH / 2, y + FOOTER_H / 2 + 8)
}

function paintBackground(ctx: CanvasRenderingContext2D, height: number): void {
  const grad = ctx.createLinearGradient(0, 0, 0, height)
  grad.addColorStop(0, BG_TOP)
  grad.addColorStop(1, BG_BOTTOM)
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, WIDTH, height)
}

function truncate(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string {
  if (ctx.measureText(text).width <= maxWidth) {
    return text
  }
  let t = text
  while (t.length > 1 && ctx.measureText(t + '…').width > maxWidth) {
    t = t.slice(0, -1)
  }
  return t + '…'
}

function renderSpread(
  opts: ExportOptions,
  images: (HTMLImageElement | null)[],
  scale: number,
): HTMLCanvasElement {
  const cardAspect = opts.cardAspect && opts.cardAspect > 0 ? opts.cardAspect : CARD_ASPECT
  const positions = [...(opts.positions ?? [])].sort((a, b) => a.order - b.order)
  const bodySize = WIDTH - PAD * 2
  const legendLine = 46
  // Two-column legend (column-major) to use the width; single column for one card.
  const legendCols = positions.length > 1 ? 2 : 1
  const legendGap = 40
  const legendColW = (bodySize - legendGap * (legendCols - 1)) / legendCols
  const legendRows = Math.ceil(positions.length / legendCols)
  const legendH = 70 + legendRows * legendLine
  const bodyY = HEADER_H
  const legendY = bodyY + bodySize + 24
  const height = legendY + legendH + FOOTER_H

  const canvas = document.createElement('canvas')
  // Backing store is scaled up for resolution; all drawing stays in logical
  // (WIDTH x height) coordinates thanks to ctx.scale().
  canvas.width = Math.round(WIDTH * scale)
  canvas.height = Math.round(height * scale)
  const ctx = canvas.getContext('2d')
  if (!ctx) {
    throw new Error('Canvas 2D context is not available.')
  }
  ctx.scale(scale, scale)
  paintBackground(ctx, height)
  drawHeader(ctx, opts.title, opts.subtitle)

  // Draw the cards first, collecting each card's corner for an upright order
  // badge. Badges are rendered in a second pass so they're never hidden by a
  // later, overlapping card.
  const fit = computeSpreadFit(positions, cardAspect)
  const badges: { x: number; y: number; r: number; order: number }[] = []
  positions.forEach((p) => {
    const card = opts.cards[p.order - 1]
    const cxPx = PAD + ((50 + (p.x - fit.cx) * fit.scale) / 100) * bodySize
    const cyPx = bodyY + ((50 + (p.y - fit.cy) * fit.scale) / 100) * bodySize
    const wPx = ((CARD_WIDTH_PCT * fit.scale) / 100) * bodySize
    const hPx = wPx * cardAspect
    drawCard(
      ctx,
      images[p.order - 1] ?? null,
      cxPx,
      cyPx,
      wPx,
      hPx,
      p.rotation,
      card?.reversed ?? false,
    )

    // Badge in the card's (rotated) top-left corner, kept inside the card.
    const r = Math.max(20, Math.min(wPx, hPx) * 0.13)
    const inset = r + 8
    const lx = -wPx / 2 + inset
    const ly = -hPx / 2 + inset
    const rad = (p.rotation * Math.PI) / 180
    badges.push({
      x: cxPx + (lx * Math.cos(rad) - ly * Math.sin(rad)),
      y: cyPx + (lx * Math.sin(rad) + ly * Math.cos(rad)),
      r,
      order: p.order,
    })
  })

  // Second pass: upright corner badges, always on top of the card art.
  badges.forEach((b) => {
    ctx.beginPath()
    ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2)
    ctx.fillStyle = 'rgba(16, 16, 22, 0.88)'
    ctx.fill()
    ctx.lineWidth = 2
    ctx.strokeStyle = GOLD
    ctx.stroke()
    ctx.fillStyle = GOLD
    ctx.font = `700 ${Math.round(b.r * 1.05)}px Georgia, serif`
    ctx.textAlign = 'center'
    ctx.fillText(String(b.order), b.x, b.y + b.r * 0.36)
  })

  // Legend panel for position labels.
  drawPanel(ctx, PAD - 16, legendY - 4, bodySize + 32, legendH)
  ctx.textAlign = 'left'
  ctx.fillStyle = GOLD
  ctx.font = '700 32px Georgia, serif'
  ctx.fillText('Card Details', PAD, legendY + 36)
  positions.forEach((p, i) => {
    const card = opts.cards[p.order - 1]
    const col = Math.floor(i / legendRows)
    const row = i % legendRows
    const colX = PAD + col * (legendColW + legendGap)
    const y = legendY + 78 + row * legendLine

    ctx.fillStyle = GOLD
    ctx.font = '700 28px Georgia, serif'
    ctx.textAlign = 'left'
    ctx.fillText(String(p.order) + '.', colX, y)

    const titlePart = p.title ? p.title + ' — ' : ''
    const name = card?.card_name ?? '—'
    const rev = card?.reversed ? '  (Reversed)' : ''
    ctx.fillStyle = TEXT
    ctx.font = '400 28px Georgia, serif'
    const line = truncate(ctx, titlePart + name + rev, legendColW - 60)
    ctx.fillText(line, colX + 50, y)
  })

  drawFooter(ctx, height - FOOTER_H, opts.readingId)
  return canvas
}

function renderGrid(
  opts: ExportOptions,
  images: (HTMLImageElement | null)[],
  scale: number,
): HTMLCanvasElement {
  const cardAspect = opts.cardAspect && opts.cardAspect > 0 ? opts.cardAspect : CARD_ASPECT
  const n = opts.cards.length
  const cols = Math.min(4, Math.max(1, n))
  const gap = 40
  const captionH = 80
  const cardW = (WIDTH - PAD * 2 - (cols - 1) * gap) / cols
  const cardH = cardW * cardAspect
  const rows = Math.ceil(n / cols)
  const bodyY = HEADER_H
  const rowH = cardH + captionH + gap
  const height = bodyY + rows * rowH + FOOTER_H

  const canvas = document.createElement('canvas')
  canvas.width = Math.round(WIDTH * scale)
  canvas.height = Math.round(height * scale)
  const ctx = canvas.getContext('2d')
  if (!ctx) {
    throw new Error('Canvas 2D context is not available.')
  }
  ctx.scale(scale, scale)
  paintBackground(ctx, height)
  drawHeader(ctx, opts.title, opts.subtitle)

  opts.cards.forEach((card, i) => {
    const col = i % cols
    const row = Math.floor(i / cols)
    const x = PAD + col * (cardW + gap)
    const y = bodyY + row * rowH
    drawCard(ctx, images[i] ?? null, x + cardW / 2, y + cardH / 2, cardW, cardH, 0, card.reversed)

    // Opaque caption background.
    drawPanel(ctx, x, y + cardH + 8, cardW, 64, false)

    ctx.textAlign = 'center'
    ctx.fillStyle = TEXT
    ctx.font = '600 26px Georgia, serif'
    const label = truncate(ctx, card.card_name, cardW - 24)
    ctx.fillText(label, x + cardW / 2, y + cardH + 42)
    if (card.reversed) {
      ctx.fillStyle = GOLD
      ctx.font = '400 22px Georgia, serif'
      ctx.fillText('(Reversed)', x + cardW / 2, y + cardH + 66)
    }
  })

  drawFooter(ctx, height - FOOTER_H, opts.readingId)
  return canvas
}

export function useReadingExport() {
  const { success, error } = useToasts()
  const exporting = ref(false)

  async function exportReading(opts: ExportOptions): Promise<void> {
    if (exporting.value) {
      return
    }
    exporting.value = true
    try {
      const scale = opts.scale && opts.scale > 0 ? opts.scale : 1
      const images = await Promise.all(opts.cards.map(loadCardImage))
      const hasSpread = !!(opts.positions && opts.positions.length > 0)
      const canvas = hasSpread ? renderSpread(opts, images, scale) : renderGrid(opts, images, scale)

      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'))
      if (!blob) {
        error('Could not generate the image.')
        return
      }

      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = opts.fileName
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      success('Reading image saved.')
    } catch (e) {
      error('Could not generate the image.', { detail: e })
    } finally {
      exporting.value = false
    }
  }

  return { exporting, exportReading }
}
