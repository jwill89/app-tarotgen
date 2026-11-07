import { afterEach } from 'vitest'
import { takeUnexpectedApiCalls } from './src/__tests__/helpers/api'

// Pin the timezone so date-formatting assertions are deterministic regardless
// of the machine running the suite.
process.env.TZ = 'UTC'

// A request stubApi had no reply for reaches the code as a network error, which
// it may handle without a trace: fail the test that made it.
afterEach(() => {
  const calls = takeUnexpectedApiCalls()
  if (calls.length > 0) {
    throw new Error(`API calls the test didn't stub: ${calls.join(', ')}`)
  }
})

/*
 * What every browser the app supports has and jsdom leaves out. Reka UI's popper
 * (selects, comboboxes, tooltips) watches its trigger's size and scrolls the
 * highlighted option into view; no layout runs, so those do nothing. Its select
 * trigger, the canvas editor and pan-zoom capture the pointer, which is kept as
 * the browser keeps it: by element and pointer id, until released.
 */
Element.prototype.scrollIntoView ??= function scrollIntoView() {}
const captured = new WeakMap<Element, Set<number>>()
Element.prototype.setPointerCapture ??= function setPointerCapture(this: Element, id: number) {
  captured.set(this, (captured.get(this) ?? new Set()).add(id))
}
Element.prototype.releasePointerCapture ??= function releasePointerCapture(
  this: Element,
  id: number,
) {
  captured.get(this)?.delete(id)
}
Element.prototype.hasPointerCapture ??= function hasPointerCapture(this: Element, id: number) {
  return captured.get(this)?.has(id) ?? false
}
globalThis.ResizeObserver ??= class ResizeObserver {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
// jsdom scrolls nothing and logs "Not implemented" for every call: the router's
// scrollBehavior and the pagers call it on each page change.
window.scrollTo = function scrollTo() {}

// jsdom's navigator has neither the Clipboard nor the Credential Management API.
// The copy buttons write to the clipboard (a spec spies on writeText, or refuses
// it); a browser without passkeys answers null credentials, and a spec that turns
// passkeys on spies on the getter.
const clipboard: Pick<Clipboard, 'writeText' | 'readText'> = {
  writeText: () => Promise.resolve(),
  readText: () => Promise.resolve(''),
}
if (!('clipboard' in navigator)) {
  Object.defineProperty(Navigator.prototype, 'clipboard', {
    configurable: true,
    get: () => clipboard,
  })
}
if (!('credentials' in navigator)) {
  Object.defineProperty(Navigator.prototype, 'credentials', {
    configurable: true,
    get: () => null,
  })
}
