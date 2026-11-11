import type { Visibility } from '@/types'

/** Who can open a reading, in the words the pages use. */
export const visibilityLabels: Record<Visibility, string> = {
  link: 'Anyone with the link',
  password: 'Password',
  private: 'Private',
}

/** What each choice means, under the control that picks it. */
export const visibilityHelp: Record<Visibility, string> = {
  link: 'Anyone with the link can open it.',
  password: 'Anyone but you needs the password to open it.',
  private: 'Only you can open it. To anyone else the link leads nowhere.',
}

/** A tag as the server stores it: lower-case, one space between words, no leading #. */
export function normalizeTag(raw: string): string {
  return raw
    .replace(/[\p{Cc}\s]+/gu, ' ')
    .trim()
    .replace(/^#+/, '')
    .trim()
    .toLowerCase()
}
