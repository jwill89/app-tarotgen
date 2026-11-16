import { describe, it, expect, afterEach } from 'vitest'
import { cspNonce } from '@/utils/cspNonce'

function addMeta(nonce: string): HTMLMetaElement {
  const meta = document.createElement('meta')
  meta.setAttribute('property', 'csp-nonce')
  meta.setAttribute('nonce', nonce)
  document.head.append(meta)
  return meta
}

describe('cspNonce', () => {
  afterEach(() => {
    document.head.querySelectorAll('meta[property="csp-nonce"]').forEach((meta) => {
      meta.remove()
    })
  })

  it('is undefined on a page with no csp-nonce meta tag', () => {
    expect(cspNonce()).toBeUndefined()
  })

  it("reads the meta tag's nonce", () => {
    addMeta('abc123==')

    expect(cspNonce()).toBe('abc123==')
  })

  // A browser empties the attribute once the page loads under a policy; the property keeps it.
  it('reads the nonce property when the attribute has been hidden', () => {
    const meta = addMeta('')
    Object.defineProperty(meta, 'nonce', { value: 'kept==' })

    expect(cspNonce()).toBe('kept==')
  })

  it('is undefined when the meta tag holds no nonce', () => {
    addMeta('')

    expect(cspNonce()).toBeUndefined()
  })
})
