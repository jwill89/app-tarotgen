import shared, { tsStylistic, vueConventions } from '@jwill89/lint-web'

// The shared front-end rules (lint/web): Vue 3 + TypeScript
// strictTypeChecked, template accessibility, the Vue conventions
// (<script setup lang="ts"> only, typed refs, no inline styles) and
// typescript-eslint's stylistic set, with Prettier's conflict list. Below them, what
// only this app needs. A rule is turned off here with its reason, or at the one line
// it doesn't fit with the reason beside it. Build and tooling config files are not
// linted.
export default [
  {
    name: 'tarot/ignores',
    ignores: [
      'dist/**',
      'coverage/**',
      'public/**',
      // What a Playwright run leaves behind (traces, the HTML report).
      'test-results/**',
      'playwright-report/**',
      'src/types/api.generated.ts', // generated from the OpenAPI spec
      '*.config.ts',
      '*.config.js',
      'knip.ts',
      'vitest.setup.ts',
    ],
  },
  ...shared,
  vueConventions,
  tsStylistic,
  {
    name: 'tarot/a11y',
    rules: {
      // A label works by wrapping its control or by `for`/`id`, and a label whose
      // control is a child component counts: BaseSelect, NumberField, ToggleSwitch
      // and the comboboxes take the id and put it on their focusable element.
      'vuejs-accessibility/label-has-for': [
        'error',
        { required: { some: ['nesting', 'id'] }, allowChildren: true },
      ],
    },
  },
  {
    // main.ts registers both Font Awesome components globally; the shared rule
    // knows FontAwesomeIcon only.
    name: 'tarot/vue',
    files: ['**/*.vue'],
    rules: {
      'vue/no-undef-components': [
        'error',
        {
          ignorePatterns: [
            '^(Router|router-)(Link|View|link|view)$',
            '^(FontAwesome(Icon|Layers)|font-awesome-(icon|layers))$',
          ],
        },
      ],
    },
  },
  {
    // A mock that does nothing is how a spec silences the console it expects to
    // be written to, holds a request open (new Promise(() => {})) or stands in a
    // callback until the test replaces it; the app's own code has none.
    name: 'tarot/test-overrides',
    files: ['src/__tests__/**'],
    rules: {
      '@typescript-eslint/no-empty-function': 'off',
    },
  },
  {
    // Every API call goes through composables/apiClient.ts, so a failure always
    // arrives as the server's message, a status and a network flag — not as a
    // raw Response each call site parses its own way.
    name: 'tarot/api-client-only',
    files: ['src/**/*.{ts,vue}'],
    ignores: ['src/composables/apiClient.ts', 'src/__tests__/**'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: "CallExpression[callee.name='fetch']",
          message: 'Call the API through apiRequest/apiFetch (composables/apiClient.ts).',
        },
        {
          selector:
            "MemberExpression[object.name=/^(window|globalThis|self)$/][property.name='fetch']",
          message: 'Call the API through apiRequest/apiFetch (composables/apiClient.ts).',
        },
      ],
    },
  },
]
