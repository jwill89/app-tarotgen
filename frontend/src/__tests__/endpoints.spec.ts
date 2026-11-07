import { describe, it, expect, vi } from 'vitest'
import { endpoints } from '@/api/endpoints'

/**
 * A file elsewhere in the repo. Read through Node, typed by hand: Vite won't
 * serve files outside frontend/, and the app's TypeScript has no Node types.
 */
async function repoFile(path: string): Promise<string> {
  const fs = await vi.importActual<{ readFileSync(path: string, encoding: 'utf8'): string }>(
    'node:fs',
  )
  const url = await vi.importActual<{ fileURLToPath(url: string): string }>('node:url')
  // Not `new URL(…, import.meta.url)` inline: Vite would rewrite that into an asset import.
  const here = import.meta.url
  return fs.readFileSync(url.fileURLToPath(new URL(`../../../${path}`, here).href), 'utf8')
}

// The backend's committed spec: every path the SPA calls must be one it serves,
// so a renamed or dropped route fails here rather than as a 404 in production.
const openapi: { paths: Record<string, unknown> } = JSON.parse(
  await repoFile('backend/openapi.json'),
)
const served = Object.keys(openapi.paths).map(
  (path) => new RegExp('^' + path.replace(/\{[^}]+\}/g, '[^/{}]+') + '$'),
)

/** Every path in a branch of the registry, with sample ids for the parameterised ones. */
function paths(node: unknown, name: string): [string, string][] {
  if (typeof node === 'string') {
    return [[name, node]]
  }
  if (typeof node === 'function') {
    const args = Array.from({ length: node.length }, (_, i) => (i === 0 ? 'public' : String(i)))
    return [[name, String(Reflect.apply(node, undefined, args))]]
  }
  if (node && typeof node === 'object') {
    return Object.entries(node).flatMap(([key, child]) => paths(child, `${name}.${key}`))
  }
  return []
}

const { admin, ...publicEndpoints } = endpoints
const all = [
  ...paths(publicEndpoints, 'endpoints'),
  // Admin paths are relative to /api/admin (useAdminApi prepends it).
  ...paths(admin, 'endpoints.admin').map(([name, path]): [string, string] => [
    name,
    '/admin' + path,
  ]),
]

describe('endpoints', () => {
  it.each(all)('%s (%s) is a path the API serves', (_name, path) => {
    const withoutQuery = path.split('?')[0] ?? path
    expect(served.some((re) => re.test(withoutQuery))).toBe(true)
  })

  it('is written without trailing slashes', () => {
    expect(all.filter(([, path]) => path.length > 1 && path.split('?')[0]?.endsWith('/'))).toEqual(
      [],
    )
  })

  it('builds the admin list filters the API reads', () => {
    expect(admin.contacts.list(true)).toBe('/contacts?show_read=1')
    expect(admin.contacts.list(false)).toBe('/contacts?show_read=0')
    expect(admin.cardReports.list(true)).toBe('/card-reports?show_resolved=1')
    expect(admin.cardReports.list(false)).toBe('/card-reports?show_resolved=0')
    expect(admin.readings.clean(30)).toBe('/readings/all?older_than_days=30')
  })

  it('puts both halves of a favourite spread in its URL', () => {
    expect(endpoints.account.favoriteById('personal', 12)).toBe('/account/favorites/personal/12')
  })

  it('asks for the journal export in the format chosen', () => {
    expect(endpoints.account.readingsExport('csv')).toBe('/account/readings/export?format=csv')
  })
})
