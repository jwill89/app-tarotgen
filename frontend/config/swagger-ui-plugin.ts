import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import type { Plugin } from 'vite'

/** Where the files are served, beside public/swagger-ui/api-docs.js, which starts them. */
const BASE = '/swagger-ui/'

/** Swagger UI's browser bundle, its licence notices and its stylesheet. */
const FILES = ['swagger-ui-bundle.js', 'swagger-ui-bundle.js.LICENSE.txt', 'swagger-ui.css']

const TYPES: Record<string, string> = {
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
}

/**
 * The API reference's viewer: Swagger UI's files from swagger-ui-dist, copied into the
 * build at /swagger-ui/ (and served there by `vite dev`), so the page /api/docs sends
 * loads it from the site's own origin under its own Content-Security-Policy, with no
 * outside host.
 */
export function swaggerUiPlugin(): Plugin {
  const require = createRequire(import.meta.url)
  const root = dirname(require.resolve('swagger-ui-dist/package.json'))
  const source = (file: string) => readFileSync(join(root, file))

  return {
    name: 'tarot-swagger-ui',
    configureServer(server) {
      server.middlewares.use((request, response, next) => {
        const path = (request.url ?? '').split('?')[0] ?? ''
        const file = FILES.find((name) => path === BASE + name)
        if (file === undefined) {
          next()
          return
        }
        response.setHeader('Content-Type', TYPES[file.slice(file.lastIndexOf('.'))] ?? '')
        response.end(source(file))
      })
    },
    generateBundle() {
      for (const file of FILES) {
        this.emitFile({ type: 'asset', fileName: BASE.slice(1) + file, source: source(file) })
      }
    },
  }
}
