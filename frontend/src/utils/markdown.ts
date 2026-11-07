import { marked } from 'marked'
import DOMPurify from 'dompurify'

/**
 * Render markdown to sanitized HTML.
 *
 * Used for BOTH admin-authored copy (CHANGELOG.md, spread descriptions) and
 * user-supplied content (contact messages, reading notes, user spread
 * descriptions), so the DOMPurify pass is load-bearing, not cosmetic: it is the
 * only thing that makes the `v-html` call sites safe. Every non-empty path goes
 * through it — keep it that way.
 */
export function renderMarkdown(md: string | null | undefined): string {
  if (!md) {
    return ''
  }
  const html = marked.parse(md, { async: false })
  return DOMPurify.sanitize(html)
}

/**
 * Markdown as one line of plain text, cut at a word to at most `max` characters
 * with an ellipsis: an excerpt for a list (the spread library), not HTML.
 */
export function markdownExcerpt(md: string | null | undefined, max: number): string {
  const html = renderMarkdown(md)
  if (!html) {
    return ''
  }
  const text = new DOMParser()
    .parseFromString(html, 'text/html')
    .body.textContent.replace(/\s+/g, ' ')
    .trim()
  if (text.length <= max) {
    return text
  }
  const cut = text.slice(0, max - 1)
  const lastSpace = cut.lastIndexOf(' ')
  return (lastSpace > max / 2 ? cut.slice(0, lastSpace) : cut).replace(/[\s,.;:–—-]+$/, '') + '…'
}
