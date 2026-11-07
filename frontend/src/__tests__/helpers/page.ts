import type { Mock } from 'vitest'
import { defineComponent, h, nextTick, type Component } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import type { Router } from 'vue-router'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ToastContainer from '@/components/ToastContainer.vue'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'
import { mountWithApp } from './app'

/*
 * Page-level helpers for specs that drive a whole view: mount it inside what
 * App.vue gives every page, then find things the way a user does (button names,
 * label text, row text, dialog titles), across the body, where Reka portals
 * dialogs, selects and toasts.
 */

/**
 * Mount a page the way App.vue frames it: inside the TooltipProvider that every
 * IconButton/Tooltip needs, beside the one shared ConfirmDialog and the toast
 * stack. A view's `confirm()` and toasts then show up in the body as they do in
 * the app.
 */
export function mountPage(view: Component, options: { router?: Router; path?: string } = {}) {
  const Page = defineComponent({
    name: 'TestPage',
    render: () => h(TooltipProvider, null, () => [h(view), h(ConfirmDialog), h(ToastContainer)]),
  })
  return mountWithApp(Page, options)
}

/**
 * Forget the toasts and any confirm left open by the previous test: both queues
 * are module state that outlives a mount.
 */
export function resetPageState(): void {
  useToasts().toasts.value = []
  useConfirm().settle(false)
}

const text = (el: Element): string => el.textContent.replace(/\s+/g, ' ').trim()

/** The one button named `name` (its text or aria-label) under `root`. */
export function button(name: string, root: ParentNode = document.body): HTMLButtonElement {
  const found = [...root.querySelectorAll('button')].filter(
    (b) => b.getAttribute('aria-label') === name || text(b) === name,
  )
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one button named "${name}", found ${found.length}`)
  }
  return only
}

/** Whether a button named `name` is on screen under `root`. */
export function hasButton(name: string, root: ParentNode = document.body): boolean {
  return [...root.querySelectorAll('button')].some(
    (b) => b.getAttribute('aria-label') === name || text(b) === name,
  )
}

/** The one link named `name` (its aria-label, else its text) under `root`. */
export function link(name: string, root: ParentNode = document.body): HTMLAnchorElement {
  const found = [...root.querySelectorAll('a')].filter(
    (a) => (a.getAttribute('aria-label') ?? text(a)) === name,
  )
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one link named "${name}", found ${found.length}`)
  }
  return only
}

/** The page's title: its one h1. */
export function pageTitle(): string | undefined {
  return document.body.querySelector('h1')?.textContent.trim()
}

/** Click and let the API calls and re-renders it starts settle. */
export async function click(el: HTMLElement): Promise<void> {
  el.click()
  await flushPromises()
}

/** The open confirm dialog (Reka portals it into the body), or null. */
export function confirmDialog(): HTMLElement | null {
  return document.body.querySelector('[role="alertdialog"]')
}

/** Answer the open confirm dialog with its confirm or its cancel button. */
export async function answerConfirm(choice: 'confirm' | 'cancel'): Promise<void> {
  const open = confirmDialog()
  if (!open) {
    throw new Error('No confirm dialog is open')
  }
  const { confirmLabel, cancelLabel } = useConfirm().state.value
  await click(button(choice === 'confirm' ? confirmLabel : cancelLabel, open))
}

/** The open modal titled `title`, or null. */
export function dialog(title: string): HTMLElement | null {
  const heading = [...document.body.querySelectorAll('[role="dialog"] .myst-modal-title')].find(
    (el) => text(el) === title,
  )
  return heading?.closest('[role="dialog"]') ?? null
}

/** The text of every toast on screen, oldest first. */
export function toasts(): string[] {
  return [...document.body.querySelectorAll('.toast-message')].map(text)
}

/** The table row with a cell reading exactly `cell`. */
export function row(cell: string, root: ParentNode = document.body): HTMLTableRowElement {
  const found = [...root.querySelectorAll('tbody tr')].filter(
    (tr): tr is HTMLTableRowElement =>
      tr instanceof HTMLTableRowElement && [...tr.cells].some((td) => text(td) === cell),
  )
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one row with "${cell}", found ${found.length}`)
  }
  return only
}

/** Each body row of the table under `root`, as its cells' text. */
export function tableRows(root: ParentNode = document.body): string[][] {
  return [...root.querySelectorAll('tbody tr')].map((tr) =>
    [...tr.querySelectorAll('td')].map(text),
  )
}

/** The column header labelled `label` under `root` (click it to sort). */
export function header(label: string, root: ParentNode = document.body): HTMLElement {
  const found = [...root.querySelectorAll('th')].filter((th) => text(th) === label)
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${label}" header, found ${found.length}`)
  }
  return only
}

/**
 * Each call a `stubApi` mock received, as `[method, url]`, or with the parsed
 * JSON body as `[method, url, body]` — to assert what a page sent, in order.
 */
export function apiCalls(api: Mock): unknown[][] {
  return api.mock.calls.map((call: unknown[]) => {
    const [url, init] = call
    const options: RequestInit = isRequestInit(init) ? init : {}
    const method = options.method ?? 'GET'
    return typeof options.body === 'string'
      ? [method, url, JSON.parse(options.body)]
      : [method, url]
  })
}

function isRequestInit(value: unknown): value is RequestInit {
  return typeof value === 'object' && value !== null
}

/** One column of the table under `root`, top to bottom. */
export function column(index: number, root: ParentNode = document.body): string[] {
  return tableRows(root).map((cells) => cells[index] ?? '')
}

/**
 * The form control named `name`: by the text of the `<label>` pointing at it (a
 * trailing required-field `*` may be left off), else by its aria-label.
 */
export function labelled(name: string): HTMLElement {
  const labels = [...document.body.querySelectorAll('label')].filter(
    (l) => text(l) === name || text(l).replace(/\s*\*$/, '') === name,
  )
  if (labels.length > 1) {
    throw new Error(`Expected one label "${name}", found ${labels.length}`)
  }
  const [label] = labels
  if (label) {
    const control = label.htmlFor ? document.getElementById(label.htmlFor) : null
    if (!control) {
      throw new Error(`Label "${name}" names no control`)
    }
    return control
  }
  const named = [...document.body.querySelectorAll<HTMLElement>('[aria-label]')].filter(
    (el) => el.getAttribute('aria-label') === name && !(el instanceof HTMLButtonElement),
  )
  const [control] = named
  if (!control || named.length !== 1) {
    throw new Error(`Expected one control named "${name}", found ${named.length}`)
  }
  return control
}

/** The current value of a text field. */
export function valueOf(el: HTMLElement): string {
  if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
    return el.value
  }
  throw new Error(`<${el.tagName.toLowerCase()}> is not a text field`)
}

/** Type into a text field (input or textarea), replacing what is there. */
export async function typeInto(el: HTMLElement, value: string): Promise<void> {
  if (!(el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)) {
    throw new Error(`<${el.tagName.toLowerCase()}> is not a text field`)
  }
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

/** Type a number into a NumberField and leave it, which is when Reka commits it. */
export async function enterNumber(el: HTMLElement, value: number): Promise<void> {
  await typeInto(el, String(value))
  el.dispatchEvent(new FocusEvent('blur'))
  await flushPromises()
}

/**
 * Send `target` a pointer event built as the browser builds one, then let the
 * re-render settle. Not `trigger()`: it assigns each option to the event after
 * building it, and jsdom's PointerEvent inherits `button`, `clientX` and the
 * modifier keys read-only from MouseEvent.
 */
export async function pointer(
  target: { element: Element },
  type: 'pointerdown' | 'pointermove' | 'pointerup',
  init: PointerEventInit = {},
): Promise<void> {
  target.element.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, ...init }))
  await nextTick()
}

/** Open a BaseSelect by its trigger and pick the option labelled `label`. */
export async function choose(trigger: HTMLElement, label: string): Promise<void> {
  // Reka's Select opens on pointerdown and picks an option on pointerup, not click.
  trigger.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'mouse' }))
  await flushPromises()
  const option = [...document.body.querySelectorAll('[role="option"]')].find(
    (o) => text(o) === label,
  )
  if (!option) {
    throw new Error(`No option "${label}" in the open select`)
  }
  option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
  await flushPromises()
}

/** The labels a BaseSelect offers, read by opening it (Escape closes it again). */
export async function optionsOf(trigger: HTMLElement): Promise<string[]> {
  trigger.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'mouse' }))
  await flushPromises()
  const labels = [...document.body.querySelectorAll('[role="option"]')].map(text)
  document.body
    .querySelector('[role="listbox"]')
    ?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  await flushPromises()
  return labels
}

/*
 * The deck-system card list (DeckCardListEditor), shared by the admin editor and
 * the public submission page.
 */

/** The card list's row headings: each card's number and title (or "Untitled Card"). */
export function cardHeadings(): string[] {
  return [...document.body.querySelectorAll('.deck-card-header')].map((h) =>
    [h.querySelector('.deck-card-number'), h.querySelector('.deck-card-title')]
      .map((el) => el?.textContent.trim() ?? '')
      .join(' '),
  )
}

/** The title input of every expanded card, in card order. */
function cardTitleInputs(): HTMLElement[] {
  return [...document.body.querySelectorAll('label')]
    .filter((l) => text(l) === 'Card Title *')
    .map((l) => document.getElementById(l.htmlFor))
    .filter((el): el is HTMLElement => el !== null)
}

/** Size the system to `names.length` cards (Total Cards), then name each one. */
export async function nameCards(names: string[]): Promise<void> {
  await enterNumber(labelled('Total Cards'), names.length)
  await click(button('Expand All'))
  const inputs = cardTitleInputs()
  for (const [i, name] of names.entries()) {
    const input = inputs[i]
    if (!input) {
      throw new Error(`No title field for card ${i + 1}`)
    }
    await typeInto(input, name)
  }
}
