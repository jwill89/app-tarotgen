import type { KnipConfig } from 'knip'

// Knip finds unused files, exports and dependencies (`npm run knip`, run in CI).
// Its Vite, Vitest and ESLint plugins find the entry points; these are the cases
// it can't see by itself.
const config: KnipConfig = {
  // Registered by URL in main.ts (navigator.serviceWorker.register), never imported.
  entry: ['public/sw.js'],
  ignore: [
    // Generated from backend/openapi.json; its unused exports are the generator's.
    'src/types/api.generated.ts',
  ],
  compilers: {
    // Bulma is pulled in by Sass @use, which Knip doesn't read: hand it the same
    // specifiers as imports (Sass built-ins like `sass:math` aren't packages).
    scss: (text: string) =>
      [...text.matchAll(/@(?:use|forward|import)\s+['"]([^'"]+)['"]/g)]
        .map(([, specifier]) => specifier)
        .filter((specifier) => !specifier.startsWith('sass:'))
        .map((specifier) => `import '${specifier}';`)
        .join('\n'),
  },
}

export default config
