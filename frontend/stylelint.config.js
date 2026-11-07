// Stylelint checks the styles for mistakes and holds the palette rule; it doesn't
// format them (Prettier does). The shared CSS rules (shared-tooling's lint/web), in
// the .vue files' style blocks as well. A rule is turned off here with its reason, or
// at the one line it doesn't fit with the reason beside it.
import { paletteRules } from '@jwill89/lint-web/stylelint'

// AUDIT §4.6: a palette colour is defined once, in tokens.css, and used through
// var(--myst-*) (a tint is color-mix(in srgb, var(--myst-*) N%, transparent)).
// Neutral white and black at some alpha — hairlines, hovers, scrims — are not
// palette colours and may be written inline.
const PALETTE_MESSAGE =
  'Use a tokens.css colour (var(--myst-*), color-mix() for a tint); only neutral white/black may be written inline (AUDIT §4.6)'

export default {
  extends: ['@jwill89/lint-web/stylelint'],
  ignoreFiles: [
    // A trimmed copy of Bulma's helper classes, vendored as-is.
    'src/assets/bulma-helpers.css',
  ],
  rules: {
    ...paletteRules(PALETTE_MESSAGE),
    // A tint here is color-mix() over the token, so tokens.css keeps no channel
    // triplets (--x-rgb) to write rgb(var(--x-rgb) / a) with, which the shared rule
    // allows. Only pure white and black may be written as rgb()/hsl().
    'declaration-property-value-disallowed-list': [
      {
        '/.*/': [
          '/\\b(?:rgba?|hsla?)\\((?!\\s*(?:255[\\s,]+255[\\s,]+255|0[\\s,]+0[\\s,]+0)[\\s,/)])/',
        ],
      },
      { message: PALETTE_MESSAGE },
    ],
    // It compares only the last compound selector, so it can't tell
    // `.spread-details-body p` from `.spread-description .content p`: 25 of its 26
    // findings here were unrelated selectors. The one real clash, Bulma's table
    // hover out-ranking ours, is fixed in style.css.
    'no-descending-specificity': null,
    // A typeface's name is written as the foundry writes it, in the heading-font
    // token too (the shared rule covers --font-* tokens only).
    'value-keyword-case': ['lower', { ignoreProperties: ['/^--font-/', '--myst-heading-font'] }],
  },
  overrides: [
    {
      // The palette itself.
      files: ['src/assets/tokens.css'],
      rules: {
        'color-no-hex': null,
        'declaration-property-value-disallowed-list': null,
      },
    },
    {
      // bulma.scss configures the Bulma modules it builds from (AGENTS.md §5). It is
      // read as SCSS and checked by the CSS rules, with Sass's own at-rules allowed:
      // stylelint-config-recommended-scss had no Stylelint 18 release (decided
      // 2026-11-06, shared-tooling 2.0.0).
      files: ['**/*.scss'],
      customSyntax: 'postcss-scss',
      rules: {
        'at-rule-no-unknown': [true, { ignoreAtRules: ['use', 'forward', 'include', 'mixin'] }],
      },
    },
  ],
}
