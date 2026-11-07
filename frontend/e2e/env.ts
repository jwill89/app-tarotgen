import { readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/** Where the end-to-end run's server listens, and its throwaway data directory. */
export const PORT = 8123
export const BASE_URL = `http://127.0.0.1:${String(PORT)}`
export const DATA_DIR = join(tmpdir(), 'tarotgen-e2e')

/** What server/prepare.php seeded, as it writes it beside the database. */
interface Seeded {
  /** The admin account's sign-in. */
  email: string
  password: string
  /** A guest's reading in the seeded spread. */
  reading: string
}

function seeded(): Seeded {
  return JSON.parse(readFileSync(join(DATA_DIR, 'e2e.json'), 'utf8')) as Seeded
}

/** The seeded admin account. */
export function adminAccount(): { email: string; password: string } {
  const { email, password } = seeded()
  return { email, password }
}

/** The id of the seeded guest reading, for its /reading/{id} page. */
export function seededReading(): string {
  return seeded().reading
}
