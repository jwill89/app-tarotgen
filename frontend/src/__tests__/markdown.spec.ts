import { describe, it, expect } from 'vitest'
import { markdownExcerpt, renderMarkdown } from '@/utils/markdown'

describe('renderMarkdown', () => {
  it('returns an empty string for empty input', () => {
    expect(renderMarkdown('')).toBe('')
    expect(renderMarkdown(null)).toBe('')
    expect(renderMarkdown(undefined)).toBe('')
  })

  it('renders basic markdown to HTML', () => {
    const html = renderMarkdown('**bold** and *italic*')
    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('<em>italic</em>')
  })

  it('renders links', () => {
    const html = renderMarkdown('[site](https://example.com)')
    expect(html).toContain('href="https://example.com"')
  })

  // Documents the security contract: admin-authored markdown is sanitised
  // before it ever reaches the DOM.
  it('strips script tags (XSS sanitisation)', () => {
    const html = renderMarkdown('hello <script>alert(1)</script> world')
    expect(html).not.toContain('<script>')
    expect(html).not.toContain('alert(1)')
  })

  it('strips inline event handlers', () => {
    const html = renderMarkdown('<img src="x" onerror="alert(1)">')
    expect(html.toLowerCase()).not.toContain('onerror')
  })
})

describe('markdownExcerpt', () => {
  it('is empty for no markdown', () => {
    expect(markdownExcerpt('', 50)).toBe('')
    expect(markdownExcerpt(null, 50)).toBe('')
  })

  it('reads as plain text on one line, markup and scripts gone', () => {
    expect(
      markdownExcerpt('# Heading\n\nA **classic** [line](https://x.test).\n\n- one\n- two', 80),
    ).toBe('Heading A classic line. one two')
    expect(markdownExcerpt('Safe <script>alert(1)</script> text', 80)).toBe('Safe text')
  })

  it('cuts a long text at a word, with an ellipsis, within the length', () => {
    const excerpt = markdownExcerpt(
      'The present moment, as it stands, and what it asks of you now.',
      30,
    )

    expect(excerpt).toBe('The present moment, as it…')
    expect(excerpt.length).toBeLessThanOrEqual(30)
  })

  it('cuts mid-word when the first word alone is too long', () => {
    expect(markdownExcerpt('Supercalifragilistic expialidocious', 10)).toBe('Supercali…')
  })
})
