import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { useReadingExport, type ExportOptions } from '@/composables/useReadingExport'
import { CARD_ASPECT } from '@/composables/useSpreadLayout'
import { useToasts } from '@/composables/useToasts'
import type { SpreadPosition } from '@/types'

/*
 * jsdom has no canvas: getContext('2d') answers null, toBlob() never calls back
 * and images never load. The spec stands in the least it needs — a 2D context
 * that records each drawing call (measureText says 10px a character) and a toBlob
 * that hands back an empty PNG, an Image whose load succeeds or fails by URL, and
 * the object URL / anchor click of the download — so it can check what lands
 * where, not pixels.
 */

interface DrawOp {
  op: string
  args: unknown[]
  fillStyle: unknown
}

let ops: DrawOp[] = []
let canvases: HTMLCanvasElement[] = []
const brokenUrls = new Set<string>()

function recordingContext() {
  const state: Record<string, unknown> = {}
  return new Proxy(state, {
    get(target, prop) {
      if (typeof prop !== 'string') {
        return undefined
      }
      if (prop === 'measureText') {
        return (text: string) => ({ width: text.length * 10 })
      }
      if (prop === 'createLinearGradient') {
        return () => ({ addColorStop: () => {} })
      }
      if (prop in target) {
        return target[prop]
      }
      return (...args: unknown[]) => {
        ops.push({ op: prop, args, fillStyle: target.fillStyle })
      }
    },
    set(target, prop, value) {
      target[String(prop)] = value
      return true
    },
  })
}

class FakeImage {
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  width = 600
  height = 1000
  #src = ''
  get src() {
    return this.#src
  }
  set src(url: string) {
    this.#src = url
    queueMicrotask(() => (brokenUrls.has(url) ? this.onerror?.() : this.onload?.()))
  }
}

const canvasProto = HTMLCanvasElement.prototype
const originalGetContext = Object.getOwnPropertyDescriptor(canvasProto, 'getContext')
const originalToBlob = Object.getOwnPropertyDescriptor(canvasProto, 'toBlob')

function giveCanvasA2dContext() {
  Object.defineProperty(canvasProto, 'getContext', {
    configurable: true,
    value(this: HTMLCanvasElement) {
      canvases.push(this)
      return recordingContext()
    },
  })
  Object.defineProperty(canvasProto, 'toBlob', {
    configurable: true,
    value: (done: BlobCallback, type = 'image/png') => {
      done(new Blob([], { type }))
    },
  })
}

let downloads: { href: string; download: string }[] = []

const ops2 = (name: string) => ops.filter((o) => o.op === name)
const texts = () => ops2('fillText').map((o) => String(o.args[0]))
const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

const card = (card_name: string, reversed = false, imgUrl = `/img/${card_name}.webp`) => ({
  card_name,
  reversed,
  imgUrl,
})

const pos = (order: number, title: string, x: number, y: number, rotation = 0) => ({
  order,
  title,
  x,
  y,
  rotation,
})

const baseOptions = (extra: Partial<ExportOptions> = {}): ExportOptions => ({
  fileName: 'reading-abc123.png',
  readingId: 'abc123',
  title: 'Two Paths',
  subtitle: 'Rider-Waite Tarot · 1 Oct 2026',
  cards: [card('The Fool'), card('The Tower', true)],
  ...extra,
})

describe('useReadingExport', () => {
  beforeEach(() => {
    ops = []
    canvases = []
    downloads = []
    brokenUrls.clear()
    useToasts().toasts.value = []
    vi.stubGlobal('Image', FakeImage)
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:reading')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      downloads.push({ href: this.href, download: this.download })
    })
    giveCanvasA2dContext()
  })

  afterEach(() => {
    if (originalGetContext) {
      Object.defineProperty(canvasProto, 'getContext', originalGetContext)
    }
    if (originalToBlob) {
      Object.defineProperty(canvasProto, 'toBlob', originalToBlob)
    }
  })

  it('downloads the image under the given file name and says so', async () => {
    const { exportReading, exporting } = useReadingExport()

    await exportReading(baseOptions())

    expect(downloads).toEqual([{ href: 'blob:reading', download: 'reading-abc123.png' }])
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:reading')
    expect(document.querySelector('a[download]')).toBeNull()
    expect(toastMessages()).toEqual(['Reading image saved.'])
    expect(exporting.value).toBe(false)
  })

  it('heads the image with the title and subtitle and signs it with the reading code', async () => {
    await useReadingExport().exportReading(baseOptions())

    expect(texts()).toEqual(
      expect.arrayContaining([
        '✦ Two Paths ✦',
        'Rider-Waite Tarot · 1 Oct 2026',
        'tarotgen.io   ·   Reading abc123',
      ]),
    )
  })

  describe('a spread reading', () => {
    // Listed out of order on purpose: the nth card drawn belongs to order n.
    const positions: SpreadPosition[] = [pos(2, 'Future', 70, 50), pos(1, 'Past', 30, 50)]

    it('keeps the layout, mirrored positions landing mirrored on the image', async () => {
      await useReadingExport().exportReading(baseOptions({ positions }))

      const [first, second] = ops2('translate').map((o) => o.args)
      // 1600px wide: the two cards sit either side of x = 800, level.
      expect(Number(first![0]) + Number(second![0])).toBeCloseTo(1600)
      expect(first![1]).toBe(second![1])
      expect(Number(first![0])).toBeLessThan(800)
    })

    it("draws each card in the deck's own proportions, cropped rather than stretched", async () => {
      await useReadingExport().exportReading(baseOptions({ positions, cardAspect: 1.5 }))

      for (const { args } of ops2('drawImage')) {
        const [, sx, sy, sw, sh, , , dw, dh] = args.map(Number)
        expect(dh! / dw!).toBeCloseTo(1.5) // the card's slot
        expect(sh! / sw!).toBeCloseTo(1.5) // the part of the art shown in it
        // The 600x1000 art is too tall for 1.5: it loses equal strips top and bottom.
        expect(sx).toBe(0)
        expect(sy).toBeCloseTo((1000 - sh!) / 2)
      }
      expect(ops2('drawImage')).toHaveLength(2)
    })

    it('turns a reversed card upside down', async () => {
      await useReadingExport().exportReading(baseOptions({ positions }))

      expect(ops2('rotate').map((o) => o.args[0])).toEqual([0, Math.PI])
    })

    it('lists every position with its card in the legend, in order', async () => {
      await useReadingExport().exportReading(baseOptions({ positions }))

      const lines = texts()
      expect(lines).toEqual(
        expect.arrayContaining([
          'Card Details',
          '1.',
          'Past — The Fool',
          '2.',
          'Future — The Tower  (Reversed)',
        ]),
      )
      expect(lines.indexOf('1.')).toBeLessThan(lines.indexOf('2.'))
    })

    it('numbers the cards on top of every card, so an overlapping card hides no badge', async () => {
      await useReadingExport().exportReading(baseOptions({ positions }))

      const lastCard = ops.map((o) => o.op).lastIndexOf('drawImage')
      const badges = ops.flatMap((o, i) =>
        o.op === 'fillText' && ['1', '2'].includes(String(o.args[0])) ? [i] : [],
      )
      expect(badges).toHaveLength(2)
      expect(Math.min(...badges)).toBeGreaterThan(lastCard)
    })

    it('shortens a legend line too long for its column', async () => {
      const long = 'The Extraordinarily Long Card Name '.repeat(6).trim()
      await useReadingExport().exportReading(
        baseOptions({ positions, cards: [card(long), card('The Tower')] }),
      )

      const line = texts().find((t) => t.startsWith('Past — '))
      expect(line?.endsWith('…')).toBe(true)
      // Two columns of (1456 - 40) / 2 px, less the 60px number gutter.
      expect((line?.length ?? 0) * 10).toBeLessThanOrEqual(708 - 60)
    })

    it('renders twice the pixels at scale 2, with the same layout', async () => {
      await useReadingExport().exportReading(baseOptions({ positions }))
      const standardOps = ops
      ops = []
      await useReadingExport().exportReading(baseOptions({ positions, scale: 2 }))

      const [standard, large] = canvases
      expect(standard!.width).toBe(1600)
      expect(large!.width).toBe(3200)
      expect(large!.height).toBe(2 * standard!.height)
      expect(ops2('scale')[0]?.args).toEqual([2, 2])
      expect(ops.filter((o) => o.op === 'translate')).toEqual(
        standardOps.filter((o) => o.op === 'translate'),
      )
    })
  })

  describe('a free draw', () => {
    const fiveCards = ['Ace', 'Two', 'Three', 'Four', 'Five'].map((n) => card(`${n} of Cups`))

    it('lays the cards out four to a row, in draw order', async () => {
      await useReadingExport().exportReading(baseOptions({ cards: fiveCards }))

      const centres = ops2('translate').map((o) => o.args.map(Number))
      const xs = centres.map(([x]) => x)
      const ys = centres.map(([, y]) => y)
      expect(xs.slice(0, 4)).toEqual([...xs.slice(0, 4)].sort((a, b) => a! - b!))
      expect(new Set(ys.slice(0, 4)).size).toBe(1)
      // The fifth starts a second row under the first.
      expect(xs[4]).toBe(xs[0])
      expect(ys[4]).toBeGreaterThan(ys[0]!)
    })

    it('grows the image by a row for every four cards', async () => {
      await useReadingExport().exportReading(baseOptions({ cards: fiveCards.slice(0, 4) }))
      await useReadingExport().exportReading(baseOptions({ cards: fiveCards }))

      const [oneRow, twoRows] = canvases
      expect(twoRows!.height).toBeGreaterThan(oneRow!.height)
    })

    it('captions each card and marks the reversed ones', async () => {
      await useReadingExport().exportReading(baseOptions())

      expect(texts()).toEqual(expect.arrayContaining(['The Fool', 'The Tower', '(Reversed)']))
      expect(texts().filter((t) => t === '(Reversed)')).toHaveLength(1)
    })

    it('uses the standard tarot proportions when the deck gives none', async () => {
      await useReadingExport().exportReading(baseOptions({ cardAspect: 0 }))

      const [, , , , , , , dw, dh] = ops2('drawImage')[0]?.args.map(Number) ?? []
      expect(dh! / dw!).toBeCloseTo(CARD_ASPECT)
    })
  })

  describe('when images will not load', () => {
    it('falls back to the master scan when the web image is missing', async () => {
      brokenUrls.add('/img/The Fool.webp')
      const fool = { ...card('The Fool'), fallbackUrl: '/img/Card_0000.png' }

      await useReadingExport().exportReading(baseOptions({ cards: [fool] }))

      const [drawn] = ops2('drawImage').map((o) => o.args[0])
      expect(drawn instanceof FakeImage && drawn.src).toBe('/img/Card_0000.png')
    })

    it('leaves an empty slot for a card with no image and still saves the rest', async () => {
      brokenUrls.add('/img/The Fool.webp')
      brokenUrls.add('/img/Card_0000.png')
      const fool = { ...card('The Fool'), fallbackUrl: '/img/Card_0000.png' }

      await useReadingExport().exportReading(baseOptions({ cards: [fool, card('The Tower')] }))

      const drawn = ops2('drawImage').map((o) => o.args[0])
      expect(drawn.map((img) => img instanceof FakeImage && img.src)).toEqual([
        '/img/The Tower.webp',
      ])
      expect(texts()).toContain('The Fool')
      expect(toastMessages()).toEqual(['Reading image saved.'])
    })
  })

  describe('when the browser cannot render', () => {
    it('says so instead of downloading when there is no 2D canvas', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      // jsdom's own answer: no canvas backend, so no context.
      if (originalGetContext) {
        Object.defineProperty(canvasProto, 'getContext', originalGetContext)
      }
      const { exportReading, exporting } = useReadingExport()

      await exportReading(baseOptions())

      expect(downloads).toEqual([])
      expect(toastMessages()).toEqual(['Could not generate the image.'])
      expect(exporting.value).toBe(false)
    })

    it('says so when the canvas produces no image', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      Object.defineProperty(canvasProto, 'toBlob', {
        configurable: true,
        value: (done: BlobCallback) => done(null),
      })

      await useReadingExport().exportReading(baseOptions())

      expect(downloads).toEqual([])
      expect(toastMessages()).toEqual(['Could not generate the image.'])
    })
  })

  it('ignores a second export while one is still rendering', async () => {
    const { exportReading, exporting } = useReadingExport()

    const first = exportReading(baseOptions())
    expect(exporting.value).toBe(true)
    await exportReading(baseOptions())
    await first
    await flushPromises()

    expect(canvases).toHaveLength(1)
    expect(downloads).toHaveLength(1)
  })
})
