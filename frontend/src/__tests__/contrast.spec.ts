import { beforeAll, describe, expect, it, vi } from 'vitest'
import { parse } from 'postcss-scss'
import { compileAsync } from 'sass-embedded'

/*
 * WCAG 2.2 contrast of every colour pair the app draws, computed from the live
 * theme: the Bulma build (src/assets/bulma.scss, compiled here as the app compiles
 * it) with tokens.css over it, and tokens.css's print block for paper. Text is
 * held to AAA, 7:1 (4.5:1 when large: 24px, or 18.66px bold); control edges, the
 * focus ring, states and meaningful icons to 3:1, where WCAG sets no AAA level.
 * A pair that can only reach AA is listed in `exceptions` with its line in
 * docs/decisions.md, and is then held to AA.
 *
 * A new colour, surface or component that sets text or an edge adds its pairs
 * here. A control that is disabled is left out: WCAG exempts inactive controls.
 */

type CssRule = Extract<ReturnType<typeof parse>['nodes'][number], { type: 'rule' }>
type Rgba = [number, number, number, number]
type Vars = Map<string, string>
type Level = 'text' | 'large' | 'graphic'

interface Rule {
  selectors: string[]
  media: string
  decls: [string, string][]
}

/** One colour drawn over a stack of backgrounds, under the names of the places it appears. */
interface Pair {
  name: string
  /** The foreground: any CSS colour, tokens included. */
  fg: string
  /** Where it sits: each a stack of layers, bottom first, the bottom one opaque. */
  on: Record<string, string[]>
  level: Level
  /** Bulma rules whose custom properties apply, in cascade order. */
  scope?: string[]
}

const AAA: Record<Level, number> = { text: 7, large: 4.5, graphic: 3 }
const AA: Record<Level, number> = { text: 4.5, large: 3, graphic: 3 }

/** Pairs that meet only AA, each with the text of its line in docs/decisions.md. */
const exceptions = new Map<string, string>()

// ── Reading the theme ────────────────────────────────────────────────────────

/** The style rules of a stylesheet, top level or one at-rule deep (`media` names it). */
function rules(css: string): Rule[] {
  const out: Rule[] = []
  const add = (rule: CssRule, media: string) => {
    const decls: [string, string][] = []
    rule.each((node) => {
      if (node.type === 'decl') {
        decls.push([node.prop, node.value])
      }
    })
    out.push({ selectors: rule.selectors.map((s) => s.replace(/\s+/g, ' ').trim()), media, decls })
  }
  parse(css).each((node) => {
    if (node.type === 'rule') {
      add(node, '')
    } else if (node.type === 'atrule') {
      node.walkRules((rule) => {
        add(rule, `${node.name} ${node.params}`)
      })
    }
  })
  return out
}

/** The custom properties set on :root, in source order; `media` adds that block's. */
function rootVars(sheets: Rule[][], media = ''): Vars {
  const vars: Vars = new Map()
  for (const sheet of sheets) {
    for (const rule of sheet) {
      if (rule.selectors.includes(':root') && (rule.media === '' || rule.media === media)) {
        for (const [prop, value] of rule.decls) {
          if (prop.startsWith('--')) {
            vars.set(prop, value)
          }
        }
      }
    }
  }
  return vars
}

/** `vars` with the declarations of every top-level rule carrying each selector laid over it. */
function scoped(sheet: Rule[], vars: Vars, selectors: string[]): Vars {
  const out = new Map(vars)
  for (const selector of selectors) {
    const found = sheet.filter((r) => r.media === '' && r.selectors.includes(selector))
    if (found.length === 0) {
      throw new Error(`No rule for ${selector}`)
    }
    for (const [prop, value] of found.flatMap((r) => r.decls)) {
      // The background shorthand resets the colour: what it sets is the background.
      out.set(prop === 'background' ? 'background-color' : prop, value)
    }
  }
  return out
}

// ── Resolving a colour as the browser does ──────────────────────────────────

function closing(text: string, open: number): number {
  let depth = 0
  for (let i = open; i < text.length; i++) {
    if (text[i] === '(') {
      depth++
    } else if (text[i] === ')' && --depth === 0) {
      return i
    }
  }
  throw new Error(`Unbalanced: ${text}`)
}

/** Splits at the commas outside any parentheses. */
function args(text: string): string[] {
  const out: string[] = []
  let depth = 0
  let start = 0
  for (let i = 0; i < text.length; i++) {
    const c = text[i]
    if (c === '(') {
      depth++
    } else if (c === ')') {
      depth--
    } else if (c === ',' && depth === 0) {
      out.push(text.slice(start, i).trim())
      start = i + 1
    }
  }
  out.push(text.slice(start).trim())
  return out
}

function substitute(expr: string, vars: Vars, depth = 0): string {
  if (depth > 50) {
    throw new Error(`Custom properties refer to each other: ${expr}`)
  }
  let out = expr
  for (let at = out.indexOf('var('); at !== -1; at = out.indexOf('var(')) {
    const end = closing(out, at + 3)
    const [name = '', ...fallback] = args(out.slice(at + 4, end))
    const value = vars.get(name) ?? (fallback.length > 0 ? fallback.join(', ') : undefined)
    if (value === undefined) {
      throw new Error(`${name} is not defined`)
    }
    out = out.slice(0, at) + substitute(value, vars, depth + 1) + out.slice(end + 1)
  }
  return out
}

/** Bulma's calc() in a colour only adds and subtracts lengths of one unit. */
function calc(expr: string): string {
  let out = expr
  for (let at = out.indexOf('calc('); at !== -1; at = out.indexOf('calc(')) {
    const end = closing(out, at + 4)
    const inner = calc(out.slice(at + 5, end))
    const terms = inner.match(/[+-]?\s*-?[\d.]+(%|deg)?/g) ?? []
    if (terms.join('').replace(/\s/g, '') !== inner.replace(/\s/g, '')) {
      throw new Error(`calc() beyond + and -: ${inner}`)
    }
    const unit = /%|deg/.exec(inner)?.[0] ?? ''
    const sum = terms.reduce((total, term) => total + parseFloat(term.replace(/\s/g, '')), 0)
    out = `${out.slice(0, at)}${String(sum)}${unit}${out.slice(end + 1)}`
  }
  return out
}

function alpha(text: string | undefined): number {
  if (text === undefined) {
    return 1
  }
  return text.endsWith('%') ? parseFloat(text) / 100 : parseFloat(text)
}

function fromHsl(h: number, s: number, l: number): [number, number, number] {
  const hue = ((h % 360) + 360) % 360
  const a = (s / 100) * Math.min(l / 100, 1 - l / 100)
  const f = (n: number) => {
    const k = (n + hue / 30) % 12
    return (l / 100 - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))) * 255
  }
  return [f(0), f(8), f(4)]
}

/** Premultiplied sRGB mixing, as CSS Color 5 defines color-mix(). */
function mix(body: string, vars: Vars): Rgba {
  const [space, first = '', second = ''] = args(body)
  if (space !== 'in srgb') {
    throw new Error(`color-mix() ${String(space)}`)
  }
  const part = (text: string): [string, number | undefined] => {
    const m = /^(.*\S)\s+([\d.]+)%$/s.exec(text)
    return m?.[1] !== undefined && m[2] !== undefined
      ? [m[1], parseFloat(m[2]) / 100]
      : [text, undefined]
  }
  const [a, pa] = part(first)
  const [b, pb] = part(second)
  let wa = pa ?? (pb === undefined ? 0.5 : 1 - pb)
  let wb = pb ?? 1 - wa
  const sum = wa + wb
  wa /= sum
  wb /= sum
  const ca = color(a, vars)
  const cb = color(b, vars)
  const out = ca[3] * wa + cb[3] * wb
  const channel = (i: 0 | 1 | 2) =>
    out === 0 ? 0 : (ca[i] * ca[3] * wa + cb[i] * cb[3] * wb) / out
  return [channel(0), channel(1), channel(2), out * Math.min(sum, 1)]
}

function color(expr: string, vars: Vars): Rgba {
  const text = calc(substitute(expr, vars)).trim()
  // `none` is what a `background` shorthand leaves: no colour at all.
  if (text === 'transparent' || text === 'none') {
    return [0, 0, 0, 0]
  }
  if (text.startsWith('#')) {
    let hex = text.slice(1)
    if (hex.length <= 4) {
      hex = hex.replace(/./g, '$&$&')
    }
    const byte = (i: number) => parseInt(hex.slice(i, i + 2), 16)
    return [byte(0), byte(2), byte(4), hex.length === 8 ? byte(6) / 255 : 1]
  }
  const m = /^([a-z-]+)\((.*)\)$/s.exec(text)
  const [fn, body] = [m?.[1], m?.[2] ?? '']
  if (fn === 'color-mix') {
    return mix(body, vars)
  }
  const parts = body.includes(',')
    ? args(body)
    : body.split('/').flatMap((half, i) => (i === 0 ? half.trim().split(/\s+/) : [half.trim()]))
  const [x = '', y = '', z = '', a] = parts
  if (fn === 'rgb' || fn === 'rgba') {
    const channel = (v: string) => (v.endsWith('%') ? parseFloat(v) * 2.55 : parseFloat(v))
    return [channel(x), channel(y), channel(z), alpha(a)]
  }
  if (fn === 'hsl' || fn === 'hsla') {
    return [...fromHsl(parseFloat(x), parseFloat(y), parseFloat(z)), alpha(a)]
  }
  throw new Error(`Not a colour: ${text}`)
}

/** The pixel the screen shows: each channel rounded to 8 bits. */
function painted([r, g, b]: Rgba): Rgba {
  return [Math.round(r), Math.round(g), Math.round(b), 1]
}

function over(top: Rgba, under: Rgba): Rgba {
  const c = (i: 0 | 1 | 2) => top[i] * top[3] + under[i] * (1 - top[3])
  return [c(0), c(1), c(2), 1]
}

function flatten(layers: string[], vars: Vars): Rgba {
  return layers.reduce<Rgba>((under, layer) => over(color(layer, vars), under), [0, 0, 0, 1])
}

function luminance([r, g, b]: Rgba): number {
  const lin = (c: number) => {
    const s = c / 255
    return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function ratio(a: Rgba, b: Rgba): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number]
  return (hi + 0.05) / (lo + 0.05)
}

// ── The surfaces ─────────────────────────────────────────────────────────────

const token = (name: string) => `var(--myst-${name})`
const tint = (name: string, percent: number) =>
  `color-mix(in srgb, var(--myst-${name}) ${String(percent)}%, transparent)`
const white = (percent: number) => `rgb(255 255 255 / ${String(percent)}%)`

const page = [token('bg')]
const chrome = [token('chrome')] // the nav, footer, menus, dialogs, select lists
const canvas = [token('bg-2')] // the spread canvas, code samples
const surface = [token('surface')] // modals, toasts, comboboxes, order badges
const panel = [token('surface-2')] // boxes, panels, tables, notifications
const raised = [token('surface-3')] // table heads, tooltips, toolbars, highlighted options
const field = ['hsl(var(--bulma-scheme-h), var(--bulma-scheme-s), var(--bulma-scheme-main-l))'] // Bulma's inputs and plain buttons
const rowHover = [...panel, white(3)] // a hovered table row
const menuHover = [...chrome, white(6)] // a highlighted menu item
const navHover = [...chrome, white(5)] // a hovered nav link
const lightbox = [...page, 'rgb(0 0 0 / 90%)'] // the lightbox's backdrop
const goldWash = (percent: number) => [...panel, tint('gold', percent)] // chips, hovered admin nav
const wash = (state: string, under = panel) => [...under, tint(state, 12)] // light tags, danger menu

const solids = { page, chrome, canvas, surface, panel, raised }
const everywhere = { ...solids, 'a hovered row': rowHover, 'a highlighted menu item': menuHover }

// ── The pairs ────────────────────────────────────────────────────────────────

const text = (name: string, fg: string, on: Record<string, string[]>): Pair => ({
  name,
  fg,
  on,
  level: 'text',
})
const graphic = (name: string, fg: string, on: Record<string, string[]>): Pair => ({
  name,
  fg,
  on,
  level: 'graphic',
})
/** A Bulma component, coloured by its own rules: its text on its background over `under`. */
const bulma = (
  name: string,
  scope: string[],
  under: Record<string, string[]> = { panel },
): Pair => ({
  name,
  fg: 'var(--bulma-pair-fg)',
  on: Object.fromEntries(
    Object.entries(under).map(([where, layers]) => [where, [...layers, 'var(--bulma-pair-bg)']]),
  ),
  level: 'text',
  scope,
})

/** A Bulma button's rules: `.button`, its hover, then `.button.<v>` for each of `variants`. */
const button = (variants: string[], hovered = false) => [
  '.button',
  ...(hovered ? ['.button:hover'] : []),
  ...variants.map((v) => `.button.${v}`),
]

const states = ['success', 'info', 'warning', 'danger']

const screen: Pair[] = [
  text('body text', token('text'), everywhere),
  text('strong text', token('text-strong'), everywhere),
  text('muted text', token('text-muted'), {
    ...everywhere,
    'the gold wash of a hovered admin link': goldWash(10),
    'the new-draw panel': [...surface, tint('aqua', 5), tint('aqua', 10)],
  }),
  text('dim text', token('text-dim'), { ...everywhere, 'the lightbox': lightbox }),
  text('placeholder', token('text-dim'), { 'a field': field, panel, surface }),
  text('grey text (.has-text-grey)', token('text-grey'), everywhere),
  text('silver text', token('silver'), { page, surface, panel }),
  text('link', token('gold'), everywhere),
  text('hovered link, chip', token('gold-bright'), {
    ...everywhere,
    'a chip': goldWash(10),
    'a light link tag': goldWash(12),
  }),
  text('current page in the nav', token('aqua'), { chrome, 'a hovered nav link': navHover }),
  { name: 'dialog title', fg: token('gold'), on: { surface }, level: 'large' },
  text('reversed, in the lightbox', token('warning'), { 'the lightbox': lightbox }),
  ...states.map((s) =>
    text(`${s} text`, token(`${s}-text`), { page, surface, panel, 'its light tag': wash(s) }),
  ),
  text('danger menu item', token('danger-text'), {
    chrome,
    highlighted: wash('danger', chrome),
  }),
  text('ink on aqua', token('on-aqua'), {
    'the aqua button': [token('aqua')],
    hovered: [token('aqua-bright')],
  }),
  text('current page number', 'rgb(255 255 255)', { 'deep aqua': [token('aqua-deep')] }),
  text('active editor tool', 'var(--bulma-link-invert)', { gold: ['var(--bulma-link)'] }),

  bulma('plain button', button([]), { 'its fill': [] }),
  bulma('plain button, hovered', button([], true), { 'its fill': [] }),
  ...['is-primary', 'is-link', 'is-success', 'is-info', 'is-warning', 'is-danger'].flatMap((v) => [
    bulma(`${v} button`, button([v]), { 'its fill': [] }),
    bulma(`${v} button, hovered`, button([v], true), { 'its fill': [] }),
  ]),
  ...['is-link', 'is-info', 'is-danger'].map((v) =>
    bulma(`${v} outlined button`, button([v, 'is-outlined']), { page, panel }),
  ),
  bulma('is-light button', button(['is-light']), { 'its fill': [] }),
  bulma('is-link is-light button', button(['is-link', 'is-link.is-light']), { 'its fill': [] }),
  bulma('is-danger is-light button', button(['is-danger', 'is-danger.is-light']), {
    'its fill': [],
  }),
  bulma('is-ghost button', button(['is-ghost']), { page, panel }),
  bulma('is-text button', button(['is-text']), { page, panel }),
  bulma('is-text button, hovered', button(['is-text', 'is-text:hover']), { page }),
  bulma('is-static button', button(['is-static']), { 'its fill': [] }),
  bulma('plain tag', ['.tag'], { 'its fill': [] }),
  ...['is-dark', 'is-warning', 'is-danger'].map((v) =>
    bulma(`${v} tag`, ['.tag', `.tag.${v}`], { 'its fill': [] }),
  ),
  bulma('is-success notification', ['.notification', '.notification.is-success'], {
    'its fill': [],
  }),
  bulma('danger help text', ['.help.is-danger'], { page, surface, panel }),
  bulma('.has-text-link', ['.has-text-link'], { page, surface, panel }),
  bulma('.has-text-grey-light', ['.has-text-grey-light'], { page, surface, panel }),

  graphic('focus ring', token('focus-ring'), { ...solids, 'a field': field }),
  graphic(
    'focused field edge',
    'hsl(var(--bulma-focus-h), var(--bulma-focus-s), var(--bulma-focus-l))',
    {
      page,
      surface,
      panel,
      'a field': field,
    },
  ),
  graphic('field edge', token('control-border'), {
    page,
    chrome,
    surface,
    panel,
    'a field': field,
  }),
  graphic('hovered select edge', token('gold'), { panel }),
  // The home page's code field: a translucent gold edge over its own panel fill.
  graphic('code field edge', `color-mix(in srgb, var(--myst-gold) 52%, var(--myst-surface-2))`, {
    page,
    surface,
    panel,
  }),
  graphic('switch handle, off', token('silver'), { 'its track': raised }),
  graphic('switch handle, on', token('surface-2'), { 'its track': [token('gold')] }),
  graphic('selected segment', token('aqua'), { 'the segments beside it': raised }),
  graphic('selected card or row edge', token('gold'), { page, raised }),
  graphic('icon button at rest', token('text-dim'), { panel, 'a hovered row': rowHover }),
  ...states.map((s) =>
    graphic(`${s} icon or dot`, token(s), { surface, panel, 'its hovered icon button': wash(s) }),
  ),
]

const paper = { page, raised }

const print: Pair[] = [
  ...['text', 'text-strong', 'text-muted', 'text-dim', 'text-grey', 'gold', 'gold-bright'].map(
    (name) => text(`--myst-${name}`, token(name), paper),
  ),
  ...states.map((s) =>
    text(`${s} text`, token(`${s}-text`), { ...paper, 'its light tag': wash(s) }),
  ),
]

/** Colour tokens drawn only as decoration (washes, hairlines, glows), or as aliases. */
const unpaired = [
  'scrim',
  'border',
  'border-strong',
  'hair-gold',
  'aqua-soft',
  'aqua-dim',
  'aqua-glow',
  'panel',
  'text-soft',
]

// ── The checks ───────────────────────────────────────────────────────────────

/** Bulma's rules, its helpers, then tokens.css's, in the order the app loads them. */
let componentRules: Rule[] = []
let tokenRules: Rule[] = []
let themes: { name: string; vars: Vars; pairs: Pair[] }[] = []

/** A path relative to this spec, on disk: the stylesheets are read as files, not imported. */
async function local(relative: string): Promise<string> {
  const url = await vi.importActual<{ fileURLToPath(url: string): string }>('node:url')
  // Not `new URL(…, import.meta.url)` inline: Vite would rewrite that into an asset import.
  const here = import.meta.url
  return url.fileURLToPath(new URL(relative, here).href)
}

async function read(relative: string): Promise<string> {
  const fs = await vi.importActual<{ readFileSync(path: string, encoding: 'utf8'): string }>(
    'node:fs',
  )
  return fs.readFileSync(await local(relative), 'utf8')
}

beforeAll(async () => {
  const compiled = await compileAsync(await local('../assets/bulma.scss'), {
    loadPaths: [await local('../../node_modules')],
    silenceDeprecations: ['if-function'],
  })
  const bulmaRules = rules(compiled.css)
  tokenRules = rules(await read('../assets/tokens.css'))
  componentRules = [
    ...bulmaRules,
    ...rules(await read('../assets/bulma-helpers.css')),
    ...tokenRules,
  ]
  themes = [
    { name: 'screen', vars: rootVars([bulmaRules, tokenRules]), pairs: screen },
    { name: 'print', vars: rootVars([bulmaRules, tokenRules], 'media print'), pairs: print },
  ]
}, 60_000)

interface Measured {
  pair: Pair
  where: string
  ratio: number
}

function measure(pairs: Pair[], vars: Vars): Measured[] {
  return pairs.flatMap((pair) => {
    const scope = pair.scope ? scoped(componentRules, vars, pair.scope) : vars
    if (pair.scope) {
      scope.set('--bulma-pair-fg', scope.get('color') ?? 'transparent')
      scope.set('--bulma-pair-bg', scope.get('background-color') ?? 'transparent')
    }
    return Object.entries(pair.on).map(([where, layers]) => {
      const under = flatten(layers, scope)
      return {
        pair,
        where,
        ratio: ratio(painted(over(color(pair.fg, scope), under)), painted(under)),
      }
    })
  })
}

describe.each(['screen', 'print'])('the %s theme', (name) => {
  const all = () => {
    const theme = themes.find((t) => t.name === name)
    if (!theme) {
      throw new Error(`No ${name} theme`)
    }
    return measure(theme.pairs, theme.vars)
  }

  it('reaches AAA for every pair that is not a recorded exception', () => {
    const short = all()
      .filter(({ pair, ratio: r }) => !exceptions.has(pair.name) && r < AAA[pair.level])
      .map(
        ({ pair, where, ratio: r }) =>
          `${pair.name} on ${where}: ${r.toFixed(2)}:1, under ${String(AAA[pair.level])}:1`,
      )
    expect(short).toEqual([])
  })

  it('keeps every recorded exception at AA, and below AAA', () => {
    const wrong = all()
      .filter(({ pair }) => exceptions.has(pair.name))
      .filter(({ pair, ratio: r }) => r < AA[pair.level] || r >= AAA[pair.level])
      .map(({ pair, where, ratio: r }) => `${pair.name} on ${where}: ${r.toFixed(2)}:1`)
    expect(wrong).toEqual([])
  })
})

describe('the recorded exceptions', () => {
  it.runIf(exceptions.size > 0)('each has its dated line in docs/decisions.md', async () => {
    const log = await read('../../../docs/decisions.md')
    const lines = log.split('\n').filter((line) => /^- \d{4}-\d{2}-\d{2} — /.test(line))
    for (const [pair, line] of exceptions) {
      expect(
        lines.some((l) => l.includes(line)),
        pair,
      ).toBe(true)
    }
  })

  it('name only pairs that exist', () => {
    const names = new Set([...screen, ...print].map((p) => p.name))
    expect([...exceptions.keys()].filter((n) => !names.has(n))).toEqual([])
  })
})

describe('the palette', () => {
  it('has every colour token in a pair, or listed as decoration', () => {
    const named = new Set(
      [...screen, ...print].flatMap(
        (p) => [p.fg, ...Object.values(p.on).flat()].join(' ').match(/--myst-[a-z0-9-]+/g) ?? [],
      ),
    )
    const defined = [...rootVars([tokenRules]).keys()].filter(
      (k) => k.startsWith('--myst-') && k !== '--myst-heading-font',
    )
    expect(defined).toContain('--myst-text')
    const missing = defined.filter((k) => !named.has(k) && !unpaired.includes(k.slice(7)))
    expect(missing).toEqual([])
  })
})
