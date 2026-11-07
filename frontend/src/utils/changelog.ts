/**
 * CHANGELOG.md (the repo root's, in Keep a Changelog form) as the admin
 * dashboard shows it. The `virtual:changelog` Vite plugin
 * (config/changelog-plugin.ts) runs this at build time, in Node, so it stays
 * plain string work: nothing from the DOM.
 */

/** One `## [x.y.z] — date` section of CHANGELOG.md. */
export interface ChangelogRelease {
  /** '5.2.0'; null for the changes not released yet (`## [Unreleased]`). */
  version: string | null
  /** As the heading gives it ('2026-10-31'); '' when it gives none. */
  date: string
  /**
   * The section's markdown below its heading: summary, notes and the Added /
   * Changed / … lists. Each heading is one level deeper than in the file, so it
   * nests under the release's own (`### Added` becomes `#### Added`).
   */
  body: string
}

/** A level-2 heading: where one section ends and the next begins. */
const SECTION = /^##\s+(.*?)\s*$/
/** `[5.2.0] — 2026-10-31`, `[5.2.0] - 2026-10-31` or `[Unreleased]`. */
const RELEASE = /^\[([^\]]+)\](?:\s*[—–-]\s*(.*))?$/
/** A link reference definition (`[5.2.0]: https://…`): the file's compare links. */
const LINK_REFERENCE = /^ {0,3}\[[^\]]+\]:\s*\S/
/** A heading to move a level down (h6 is as deep as markdown goes). */
const DEMOTABLE_HEADING = /^(#{1,5})(\s)/
const HEADING = /^#{1,6}\s/
/** A code fence: ``` or ~~~, three or more. */
const FENCE = /^ {0,3}(`{3,}|~{3,})/

interface Section {
  heading: string
  lines: string[]
}

/**
 * The releases in CHANGELOG.md, in the file's order (newest first). The file's
 * own title and intro (before the first section) and its link references are
 * left out, as is an [Unreleased] section that lists nothing. A level-2 heading
 * that isn't `[version]` ends a section but isn't a release.
 */
export function parseChangelog(markdown: string): ChangelogRelease[] {
  const releases: ChangelogRelease[] = []
  for (const section of sections(markdown)) {
    const heading = RELEASE.exec(section.heading)
    if (!heading) {
      continue
    }
    const label = (heading[1] ?? '').trim()
    const unreleased = label.toLowerCase() === 'unreleased'
    if (unreleased && !section.lines.some(isContent)) {
      continue
    }
    releases.push({
      version: unreleased ? null : label,
      date: (heading.at(2) ?? '').trim(),
      body: withoutEdgeBlankLines(section.lines).join('\n'),
    })
  }
  return releases
}

/**
 * Split at each level-2 heading outside code fences. Inside a section, link
 * references go and headings move a level down; fenced code stays as written.
 */
function sections(markdown: string): Section[] {
  const found: Section[] = []
  let fence: string | null = null
  for (const line of markdown.split(/\r?\n/)) {
    const current = found.at(-1)
    const marker = FENCE.exec(line)?.[1]
    if (fence !== null) {
      // A fence closes on its own character, at least as many of them.
      if (marker?.startsWith(fence.charAt(0)) && marker.length >= fence.length) {
        fence = null
      }
      current?.lines.push(line)
      continue
    }
    const heading = SECTION.exec(line)
    if (heading) {
      found.push({ heading: heading[1] ?? '', lines: [] })
    } else if (marker) {
      fence = marker
      current?.lines.push(line)
    } else if (!LINK_REFERENCE.test(line)) {
      current?.lines.push(line.replace(DEMOTABLE_HEADING, '#$1$2'))
    }
  }
  return found
}

/** Anything but a blank line or a heading: an empty `### Added` lists nothing. */
function isContent(line: string): boolean {
  return line.trim() !== '' && !HEADING.test(line)
}

function withoutEdgeBlankLines(lines: string[]): string[] {
  let start = 0
  let end = lines.length
  while (start < end && lines[start]?.trim() === '') {
    start++
  }
  while (end > start && lines[end - 1]?.trim() === '') {
    end--
  }
  return lines.slice(start, end)
}
