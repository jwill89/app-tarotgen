import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { h, nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import LightboxOverlay from '@/components/LightboxOverlay.vue'
import { useCardReports } from '@/composables/useCardReports'
import { useToasts } from '@/composables/useToasts'
import { cardBackImageUrls, cardImageUrls } from '@/utils/cardImages'
import { mountWithApp, cleanup, stubApi } from './helpers/app'
import type { ReadingCard } from '@/types'

// Deck 40 is only used here: reported cards are module state that outlives a test.
const DECK = 40

function card(card_id: number, card_name: string, reversed = false): ReadingCard {
  const urls = cardImageUrls(DECK, card_id)
  return {
    card_id,
    card_name,
    reversed,
    imgUrl: urls.display,
    pngUrl: urls.png,
    thumbUrl: urls.thumb,
  }
}

const fool = card(1, 'The Fool')
const magician = card(2, 'The Magician', true)
const priestess = card(3, 'The High Priestess')

async function openLightbox(
  initialIndex = 0,
  cards = [fool, magician, priestess],
  revealed: ReadonlySet<number> = new Set(cards.keys()),
) {
  // The report button sits in a Tooltip, which needs the provider App.vue mounts.
  const { wrapper } = await mountWithApp(TooltipProvider, {
    slots: {
      default: () =>
        h(LightboxOverlay, {
          cards,
          revealed,
          cardBack: cardBackImageUrls(DECK),
          initialIndex,
          deckId: DECK,
        }),
    },
  })
  return wrapper.getComponent(LightboxOverlay)
}

const dialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]')
const image = () => dialog()?.querySelector('img')
const button = (name: string) =>
  [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
    (b) => b.getAttribute('aria-label') === name || b.textContent.trim() === name,
  )
const caption = () => document.body.querySelector('.lightbox-caption')?.textContent.trim()

async function press(key: string): Promise<void> {
  ;(document.activeElement ?? document.body).dispatchEvent(
    new KeyboardEvent('keydown', { key, bubbles: true }),
  )
  await flushPromises()
}

const viewport = () => document.body.querySelector<HTMLElement>('.lightbox-viewport')
const zoomLevel = () => button('Reset zoom')?.textContent.trim()

interface Point {
  clientX: number
  clientY: number
}

/** A touch event carrying these touches (jsdom has no Touch to build the lists with). */
function touch(type: string, touches: Point[], changed: Point[] = touches): Event {
  const event = new Event(type, { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'touches', { value: touches })
  Object.defineProperty(event, 'changedTouches', { value: changed })
  return event
}

async function swipe(from: Point, to: Point): Promise<void> {
  viewport()?.dispatchEvent(touch('touchstart', [from]))
  viewport()?.dispatchEvent(touch('touchend', [], [to]))
  await nextTick()
}

/*
 * The image's transform read back as numbers. jsdom (31 on) writes a transform the shortest way
 * CSS allows, `translate(0px) scale(-1)` for `translate(0px, 0px) scale(-1, -1)`, so a second
 * value left out is its default: 0 for a translate's y, the x scale for a scale's y.
 */
function transformed(): { pan: [number, number]; scale: [number, number] } {
  const transform = image()?.style.transform ?? ''
  const moved = /translate\((-?[\d.]+)px(?:,\s*(-?[\d.]+)px)?\)/.exec(transform)
  const sized = /scale\((-?[\d.]+)(?:,\s*(-?[\d.]+))?\)/.exec(transform)
  return {
    pan: moved ? [Number(moved[1]), Number(moved[2] ?? 0)] : [NaN, NaN],
    scale: sized ? [Number(sized[1]), Number(sized[2] ?? sized[1])] : [NaN, NaN],
  }
}

/** The image's on-screen offset, read from its transform. */
function pan(): [number, number] {
  return transformed().pan
}

describe('LightboxOverlay', () => {
  beforeEach(() => {
    useToasts().toasts.value = []
  })
  afterEach(cleanup)

  it('opens a dialog named after the card, showing its full-size image', async () => {
    await openLightbox(0)

    expect(dialog()?.textContent).toContain('The Fool')
    expect(image()?.getAttribute('src')).toBe(fool.imgUrl)
    expect(image()?.getAttribute('alt')).toBe('The Fool')
    expect(document.body.textContent).toContain('Image 1 of 3')
  })

  it('pages through the cards with the arrow keys, stopping at either end', async () => {
    await openLightbox(1)

    await press('ArrowRight')
    expect(caption()).toBe('The High Priestess')
    await press('ArrowRight')
    expect(caption()).toBe('The High Priestess')

    await press('ArrowLeft')
    await press('ArrowLeft')
    expect(caption()).toBe('The Fool')
  })

  it('pages back past the first card to the card back, and no further', async () => {
    await openLightbox(0)

    await press('ArrowLeft')
    expect(caption()).toBe('Card Back')
    expect(image()?.getAttribute('src')).toBe(cardBackImageUrls(DECK).display)
    // The back is not "Image n of 3", and has nothing to report.
    expect(document.body.textContent).not.toContain('of 3')
    expect(button('Report a problem')).toBeUndefined()
    expect(button('Previous image')).toBeUndefined()

    await press('ArrowLeft')
    expect(caption()).toBe('Card Back')
  })

  it('shows a card still face down on the page by its back, named only by its number', async () => {
    // The Fool is face up; the Magician and the Priestess are not yet turned.
    await openLightbox(0, [fool, magician, priestess], new Set([0]))

    await press('ArrowRight')

    expect(caption()).toBe('Face-down card 2')
    expect(image()?.getAttribute('src')).toBe(cardBackImageUrls(DECK).display)
    expect(image()?.getAttribute('alt')).toBe('Face-down card 2')
    expect(transformed().scale).toEqual([1, 1])
    expect(document.body.textContent).toContain('Image 2 of 3')
    // Nothing in the dialog names it, and there is nothing to report or turn upright.
    expect(dialog()?.textContent).not.toContain('Magician')
    expect(button('View Upright')).toBeUndefined()
    expect(button('Report a problem')).toBeUndefined()

    await press('ArrowLeft')
    expect(caption()).toBe('The Fool')
  })

  it('offers previous/next buttons only where there is somewhere to go', async () => {
    await openLightbox(2)

    expect(button('Next image')).toBeUndefined()
    button('Previous image')?.click()
    await flushPromises()

    expect(caption()).toBe('The Magician (Reversed)')
    expect(button('Next image')).toBeDefined()
  })

  it('zooms with the toolbar and the +, - and 0 keys, within 100–400%', async () => {
    await openLightbox(0)
    const level = () => button('Reset zoom')?.textContent.trim()

    expect(level()).toBe('100%')
    expect(button('Zoom out')?.disabled).toBe(true)

    await press('+')
    expect(level()).toBe('175%')
    await press('=')
    expect(level()).toBe('250%')
    await press('-')
    expect(level()).toBe('175%')
    await press('0')
    expect(level()).toBe('100%')

    for (let i = 0; i < 5; i++) {
      button('Zoom in')?.click()
    }
    await nextTick()
    expect(level()).toBe('400%')
    expect(button('Zoom in')?.disabled).toBe(true)

    button('Reset zoom')?.click()
    await nextTick()
    expect(level()).toBe('100%')
  })

  it('hides the page arrows while zoomed, so a drag pans instead', async () => {
    await openLightbox(1)

    await press('+')

    expect(button('Previous image')).toBeUndefined()
    expect(button('Next image')).toBeUndefined()
  })

  it('resets the zoom when moving to another card', async () => {
    await openLightbox(1)

    await press('+')
    await press('0')
    await press('+')
    // The arrow buttons are hidden while zoomed, but the keyboard still pages.
    await press('ArrowRight')

    expect(button('Reset zoom')?.textContent.trim()).toBe('100%')
  })

  it('shows a reversed card upside down, with a button to view it upright', async () => {
    await openLightbox(1)

    expect(transformed().scale).toEqual([-1, -1])
    button('View Upright')?.click()
    await nextTick()

    expect(transformed().scale).toEqual([1, 1])
    expect(button('View Reversed')).toBeDefined()
  })

  it('falls back to the master PNG when the display image is missing', async () => {
    await openLightbox(0)

    image()?.dispatchEvent(new Event('error'))
    await nextTick()

    expect(image()?.getAttribute('src')).toBe(fool.pngUrl)
  })

  it('closes on Escape and from the close button', async () => {
    const lightbox = await openLightbox(0)

    await press('Escape')
    expect(lightbox.emitted('close')).toHaveLength(1)

    button('Close lightbox')?.click()
    await flushPromises()
    expect(lightbox.emitted('close')).toHaveLength(2)
  })

  it('opens the report form for the card on show, and leaves the keys to it', async () => {
    await openLightbox(0)

    button('Report a problem')?.click()
    await flushPromises()

    const form = [...document.body.querySelectorAll('[role="dialog"]')].at(-1)
    expect(form?.textContent).toContain('Report a problem')
    expect(form?.textContent).toContain('The Fool')

    // Arrows belong to the form's radio group now, not to paging the lightbox.
    await press('ArrowRight')
    await press('+')
    expect(caption()).toBe('The Fool')
    expect(button('Reset zoom')?.textContent.trim()).toBe('100%')
  })

  it('closes only the report form on Escape, keeping the lightbox open', async () => {
    const lightbox = await openLightbox(0)
    button('Report a problem')?.click()
    await flushPromises()

    await press('Escape')

    expect(document.body.textContent).not.toContain("What's wrong?")
    expect(lightbox.emitted('close')).toBeUndefined()
    expect(caption()).toBe('The Fool')
  })

  it('marks a card already reported and offers no second report', async () => {
    stubApi({ 'POST /card-reports': { status: 201, body: { status: 'reported' } } })
    await useCardReports().submitCardReport({
      deckId: DECK,
      cardId: 3,
      cardName: 'The High Priestess',
      reason: 'cropping',
      details: '',
    })

    await openLightbox(2)

    const reported = button('Reported')
    expect(reported?.disabled).toBe(true)
    expect(button('Report a problem')).toBeUndefined()

    // Paging to a card nobody reported offers the report again.
    await press('ArrowLeft')
    expect(button('Report a problem')?.disabled).toBe(false)
  })

  describe('pointer and touch', () => {
    // jsdom lays nothing out: give the image a size, so a zoomed image has
    // somewhere to pan to (at 250%, 200x300 leaves 150px either side), centred
    // at (150, 100), where the zooms below are aimed so they don't shift it.
    const centre = { clientX: 150, clientY: 100 }
    function sizeImage(): void {
      const img = image()
      if (!img) {
        throw new Error('No image')
      }
      Object.defineProperty(img, 'clientWidth', { value: 200 })
      Object.defineProperty(img, 'clientHeight', { value: 300 })
      vi.spyOn(img, 'getBoundingClientRect').mockReturnValue(
        DOMRect.fromRect({ x: 50, y: -50, width: 200, height: 300 }),
      )
    }

    it('zooms in and out with the scroll wheel', async () => {
      await openLightbox(0)

      viewport()?.dispatchEvent(new WheelEvent('wheel', { deltaY: -100, cancelable: true }))
      await nextTick()
      expect(zoomLevel()).toBe('115%')

      viewport()?.dispatchEvent(new WheelEvent('wheel', { deltaY: 100, cancelable: true }))
      await nextTick()
      expect(zoomLevel()).toBe('100%')
    })

    it('toggles a close-up with a double-click', async () => {
      await openLightbox(0)

      viewport()?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
      await nextTick()
      expect(zoomLevel()).toBe('250%')

      viewport()?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
      await nextTick()
      expect(zoomLevel()).toBe('100%')
    })

    it('pans a zoomed image by dragging, no further than its edges', async () => {
      await openLightbox(0)
      sizeImage()
      viewport()?.dispatchEvent(new MouseEvent('dblclick', { ...centre, bubbles: true }))
      await nextTick()

      viewport()?.dispatchEvent(
        new MouseEvent('mousedown', { clientX: 0, clientY: 0, bubbles: true }),
      )
      window.dispatchEvent(new MouseEvent('mousemove', { clientX: 100, clientY: -40 }))
      await nextTick()
      expect(pan()).toEqual([100, -40])

      window.dispatchEvent(new MouseEvent('mousemove', { clientX: 900, clientY: 0 }))
      await nextTick()
      expect(pan()).toEqual([150, 0])

      // Released: moving the mouse no longer drags.
      window.dispatchEvent(new MouseEvent('mouseup'))
      window.dispatchEvent(new MouseEvent('mousemove', { clientX: 0, clientY: 0 }))
      await nextTick()
      expect(pan()).toEqual([150, 0])
    })

    it('does not drag an image that is not zoomed', async () => {
      await openLightbox(0)
      sizeImage()

      viewport()?.dispatchEvent(
        new MouseEvent('mousedown', { clientX: 0, clientY: 0, bubbles: true }),
      )
      window.dispatchEvent(new MouseEvent('mousemove', { clientX: 100, clientY: 100 }))
      await nextTick()

      expect(pan()).toEqual([0, 0])
    })

    it('pages through the cards with a sideways swipe', async () => {
      await openLightbox(1)

      await swipe({ clientX: 300, clientY: 100 }, { clientX: 100, clientY: 110 })
      expect(caption()).toBe('The High Priestess')

      // A second touch this soon would read as a double-tap: let time pass.
      vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 1000)
      await swipe({ clientX: 100, clientY: 100 }, { clientX: 300, clientY: 90 })
      expect(caption()).toBe('The Magician (Reversed)')
    })

    it('ignores a short or mostly vertical swipe', async () => {
      await openLightbox(1)

      await swipe({ clientX: 200, clientY: 100 }, { clientX: 170, clientY: 100 })
      vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 1000)
      await swipe({ clientX: 200, clientY: 100 }, { clientX: 120, clientY: 300 })

      expect(caption()).toBe('The Magician (Reversed)')
    })

    it('toggles a close-up with a double-tap', async () => {
      await openLightbox(0)
      const spot = { clientX: 50, clientY: 50 }

      await swipe(spot, spot)
      await swipe(spot, spot)

      expect(zoomLevel()).toBe('250%')
    })

    it('zooms with a pinch, and then a one-finger drag pans instead of paging', async () => {
      await openLightbox(1)
      sizeImage()

      viewport()?.dispatchEvent(
        touch('touchstart', [
          { clientX: 100, clientY: 100 },
          { clientX: 200, clientY: 100 },
        ]),
      )
      viewport()?.dispatchEvent(
        touch('touchmove', [
          { clientX: 50, clientY: 100 },
          { clientX: 250, clientY: 100 },
        ]),
      )
      viewport()?.dispatchEvent(touch('touchend', [], [{ clientX: 250, clientY: 100 }]))
      await nextTick()
      expect(zoomLevel()).toBe('200%')

      viewport()?.dispatchEvent(touch('touchstart', [{ clientX: 300, clientY: 100 }]))
      viewport()?.dispatchEvent(touch('touchmove', [{ clientX: 250, clientY: 120 }]))
      viewport()?.dispatchEvent(touch('touchend', [], [{ clientX: 250, clientY: 120 }]))
      await nextTick()
      expect(pan()).toEqual([-50, 20])
      expect(caption()).toBe('The Magician (Reversed)')
    })

    it('closes on a click on the backdrop, but not on its scrollbar', async () => {
      const lightbox = await openLightbox(0)
      // Reka starts listening for outside pointerdowns a macrotask after opening.
      await new Promise((resolve) => setTimeout(resolve, 0))
      const overlay = document.body.querySelector('.lightbox-overlay')
      const pointerDownAt = async (offsetX: number) => {
        const event = new PointerEvent('pointerdown', { bubbles: true })
        // A scrollbar reports an offset past the element's (here zero-width) box.
        Object.defineProperty(event, 'offsetX', { value: offsetX })
        overlay?.dispatchEvent(event)
        await flushPromises()
      }

      await pointerDownAt(12)
      expect(lightbox.emitted('close')).toBeUndefined()

      await pointerDownAt(0)
      expect(lightbox.emitted('close')).toHaveLength(1)
    })
  })
})
