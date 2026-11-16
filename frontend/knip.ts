import type { KnipConfig } from 'knip'

// Knip finds unused files, exports and dependencies (`npm run knip`, run in CI).
// Its Vite, Vitest and ESLint plugins find the entry points; these are the cases
// it can't see by itself.
const config: KnipConfig = {
  // Loaded by URL, never imported: the service worker by main.ts
  // (navigator.serviceWorker.register), the API reference's start-up script by the
  // page /api/docs sends.
  entry: ['public/sw.js', 'public/swagger-ui/api-docs.js'],
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
