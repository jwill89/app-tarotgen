import type { PendingSpread, Spread, SpreadCategory, SpreadPosition } from '@/types'

/*
 * The library the admin Spreads specs share: three categories, three spreads in
 * them and two submissions waiting for review, as the admin API sends them.
 */

export const position = (order: number, title: string, x = 20 * order, y = 50): SpreadPosition => ({
  order,
  title,
  x,
  y,
  rotation: 0,
})

const category = (
  category_id: number,
  name: string,
  sort_order: number,
  spread_count = 0,
): SpreadCategory => ({ category_id, name, sort_order, spread_count })

export const categories: SpreadCategory[] = [
  category(1, 'General', 1, 2),
  category(2, 'Love & relationships', 2, 1),
  category(3, 'Career', 3),
]

export const spread = (
  spread_id: number,
  name: string,
  positions: SpreadPosition[],
  category_id = 1,
): Spread => {
  // Its category's name and place, as the API joins them in.
  const joined = categories.find((c) => c.category_id === category_id)
  return {
    spread_id,
    name,
    description: `About ${name}`,
    card_count: positions.length,
    category_id,
    category_name: joined?.name ?? '',
    category_sort_order: joined?.sort_order ?? 0,
    positions,
  }
}

export const pendingSpread = (
  pending_id: number,
  name: string,
  overrides: Partial<PendingSpread> = {},
): PendingSpread => ({
  pending_id,
  name,
  description: '',
  card_count: 2,
  positions: [position(1, 'Heart'), position(2, 'Mind')],
  submitter: 'Morgana',
  user_id: 7,
  submitted_at: '2026-10-27T08:30:00Z',
  ...overrides,
})

export const threeCard = spread(
  1,
  'Three Card',
  [position(1, 'Past'), position(2, 'Present'), position(3, 'Future')],
  2,
)
const celtic = spread(2, 'Celtic Cross', [position(1, 'Situation'), position(2, 'Challenge')])
const single = spread(3, 'Single Card', [position(1, 'Answer')])
export const spreads = [celtic, single, threeCard]

// Out of order on purpose: the preview lists positions by their `order`.
export const crossroads = pendingSpread(5, 'Crossroads', {
  description: 'Two paths, one choice.',
  card_count: 3,
  positions: [position(3, 'Choice'), position(1, ''), position(2, 'Path B')],
})
export const anonymous = pendingSpread(6, 'Quick Look', { submitter: '', user_id: null })
