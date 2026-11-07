import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import CardReportDialog from '@/components/CardReportDialog.vue'
import { useCardReports } from '@/composables/useCardReports'
import { useToasts } from '@/composables/useToasts'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { cardImageUrls } from '@/utils/cardImages'
import { mountWithApp, cleanup, stubApi } from './helpers/app'
import type { ReadingCard } from '@/types'

// Deck 41 is only used here: reported cards are module state that outlives a test.
const DECK = 41

function card(card_id: number, card_name: string): ReadingCard {
  const urls = cardImageUrls(DECK, card_id)
  return {
    card_id,
    card_name,
    reversed: false,
    imgUrl: urls.display,
    pngUrl: urls.png,
    thumbUrl: urls.thumb,
  }
}

async function openForm(target = card(17, 'The Star')) {
  const { wrapper } = await mountWithApp(CardReportDialog, {
    props: { deckId: DECK, card: target },
  })
  return wrapper
}

const radio = (label: string) =>
  [...document.body.querySelectorAll<HTMLElement>('[role="radio"]')].find((r) =>
    r.textContent.includes(label),
  )
const details = () => document.body.querySelector<HTMLTextAreaElement>('#card-report-details')
const sendButton = () =>
  [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
    (b) => b.textContent.trim() === 'Send report',
  )
const buttonNamed = (name: string) =>
  [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
    (b) => b.textContent.trim() === name || b.getAttribute('aria-label') === name,
  )

async function choose(label: string): Promise<void> {
  radio(label)?.click()
  await flushPromises()
}

async function type(text: string): Promise<void> {
  const box = details()
  if (!box) {
    throw new Error('No details box')
  }
  box.value = text
  box.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function send(): Promise<void> {
  sendButton()?.click()
  await flushPromises()
}

describe('CardReportDialog', () => {
  beforeEach(() => {
    useToasts().toasts.value = []
  })
  afterEach(cleanup)

  it('names the card being reported and lists every reason', async () => {
    await openForm()

    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('Reporting')
    expect(dialog?.textContent).toContain('The Star')
    expect(dialog?.querySelector('img')?.getAttribute('src')).toBe(cardImageUrls(DECK, 17).thumb)
    expect(
      [...document.body.querySelectorAll('[role="radio"]')].map((r) =>
        r.getAttribute('aria-labelledby'),
      ),
    ).toEqual([
      'card-report-reason-artefact',
      'card-report-reason-cropping',
      'card-report-reason-quality',
      'card-report-reason-wrong_card',
      'card-report-reason-other',
    ])
    expect(document.getElementById('card-report-reason-wrong_card')?.textContent).toBe('Wrong card')
  })

  it('drops the thumbnail when it fails to load', async () => {
    await openForm()

    document.body.querySelector('.report-card img')?.dispatchEvent(new Event('error'))
    await flushPromises()

    expect(document.body.querySelector('.report-card img')).toBeNull()
  })

  it('cannot be sent until a reason is chosen', async () => {
    await openForm()

    expect(sendButton()?.disabled).toBe(true)
    await choose('Scan artefact')

    expect(radio('Scan artefact')?.getAttribute('aria-checked')).toBe('true')
    expect(sendButton()?.disabled).toBe(false)
  })

  it('asks for the detail that fits the chosen reason', async () => {
    await openForm()

    await choose('Cropped or crooked')
    expect(details()?.placeholder).toBe('Which edge? e.g. the bottom border is cut off')

    await choose('Wrong card')
    expect(details()?.placeholder).toBe('Which card does the image show instead?')
  })

  it('requires details for "Something else", which says nothing on its own', async () => {
    await openForm()

    await choose('Something else')
    expect(document.body.textContent).toContain('(required)')
    expect(details()?.required).toBe(true)
    expect(sendButton()?.disabled).toBe(true)

    await type('   ')
    expect(sendButton()?.disabled).toBe(true)

    await type('The corner is torn')
    expect(sendButton()?.disabled).toBe(false)
  })

  it('counts the characters typed against the limit', async () => {
    await openForm()

    expect(document.body.querySelector('.report-form__count')).toBeNull()
    await type('Dust')

    expect(document.body.querySelector('.report-form__count')?.textContent.trim()).toBe('4 / 500')
    expect(details()?.maxLength).toBe(500)
  })

  it('sends the card, reason and trimmed details, thanks the user and closes', async () => {
    const api = stubApi({ 'POST /card-reports': { status: 201, body: { status: 'reported' } } })
    const form = await openForm()

    await choose('Scan artefact')
    await type('  A speck near the top  ')
    await send()

    expect(JSON.parse(String(api.mock.calls[0]![1]?.body))).toEqual({
      deck_id: DECK,
      card_id: 17,
      card_name: 'The Star',
      reason: 'artefact',
      details: 'A speck near the top',
    })
    expect(useToasts().toasts.value.map((t) => [t.type, t.message])).toEqual([
      ['success', 'Thanks! Your report has been sent.'],
    ])
    expect(form.emitted('close')).toHaveLength(1)
    expect(useCardReports().hasReported(DECK, 17)).toBe(true)
  })

  it('says so, without an error, when the card was already reported recently', async () => {
    stubApi({ 'POST /card-reports': { body: { status: 'already_reported' } } })
    const form = await openForm(card(18, 'The Moon'))

    await choose('Poor image quality')
    await send()

    expect(useToasts().toasts.value.map((t) => [t.type, t.message])).toEqual([
      ['info', "You've already reported this card recently. Thanks!"],
    ])
    expect(form.emitted('close')).toHaveLength(1)
  })

  it("shows the server's error and stays open so the user can retry", async () => {
    stubApi({
      'POST /card-reports': { status: 429, body: { error: 'Too many reports. Try later.' } },
    })
    const form = await openForm(card(19, 'The Sun'))

    await choose('Scan artefact')
    await send()

    expect(document.body.querySelector('[role="alert"]')?.textContent).toBe(
      'Too many reports. Try later.',
    )
    expect(form.emitted('close')).toBeUndefined()
    expect(sendButton()?.disabled).toBe(false)
    expect(useCardReports().hasReported(DECK, 19)).toBe(false)
  })

  it('says when the report could not be sent for lack of a connection', async () => {
    // A rejected fetch is how the browser reports being offline.
    const api = stubApi({})
    api.mockRejectedValue(new TypeError('Failed to fetch'))
    await openForm(card(20, 'Judgement'))

    await choose('Scan artefact')
    await send()

    expect(document.body.querySelector('[role="alert"]')?.textContent).toBe(NETWORK_ERROR_MESSAGE)
  })

  it('closes on a backdrop click only while nothing has been typed', async () => {
    const form = await openForm()
    // Reka starts listening for outside pointerdowns a macrotask after opening.
    await new Promise((resolve) => setTimeout(resolve, 0))
    const clickBackdrop = async () => {
      document.body
        .querySelector('.myst-modal-overlay')
        ?.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      await flushPromises()
    }

    await type('The corner is torn')
    await clickBackdrop()
    expect(form.emitted('close')).toBeUndefined()

    await type('')
    await clickBackdrop()
    expect(form.emitted('close')).toHaveLength(1)
  })

  it('closes from Cancel, the close button and Escape without sending anything', async () => {
    const api = stubApi({})
    const form = await openForm()

    buttonNamed('Cancel')?.click()
    buttonNamed('Close')?.click()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(form.emitted('close')).toHaveLength(3)
    expect(api).not.toHaveBeenCalled()
  })
})
