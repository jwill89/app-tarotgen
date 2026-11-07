import { describe, it, expect, afterEach } from 'vitest'
import SpreadLayoutPreview from '@/components/SpreadLayoutPreview.vue'
import { mountWithApp, cleanup } from './helpers/app'
import type { SpreadPosition } from '@/types'

const at = (order: number, x: number, y: number, rotation = 0): SpreadPosition => ({
  order,
  title: `Position ${order}`,
  x,
  y,
  rotation,
})

async function mountPreview(positions: SpreadPosition[], thumb = false) {
  const { wrapper } = await mountWithApp(SpreadLayoutPreview, { props: { positions, thumb } })
  return wrapper
}

describe('SpreadLayoutPreview', () => {
  afterEach(cleanup)

  it('draws a numbered outline for each position, in order, on a grid', async () => {
    const wrapper = await mountPreview([at(2, 60, 50), at(1, 40, 50)])

    expect(wrapper.classes()).toEqual(
      expect.arrayContaining(['spread-canvas', 'spread-canvas--preview', 'has-grid']),
    )
    expect(wrapper.findAll('.preview-number').map((n) => n.text())).toEqual(['1', '2'])
    expect(wrapper.attributes('aria-hidden')).toBeUndefined()
  })

  it('zooms a small spread to fill the canvas, centred', async () => {
    const wrapper = await mountPreview([at(1, 40, 50), at(2, 60, 50)])

    const [left, right] = wrapper.findAll<HTMLElement>('.spread-card').map((c) => c.element.style)
    // Centred on the middle, and wider than an unzoomed card (11%).
    expect(parseFloat(left!.left) + parseFloat(right!.left)).toBeCloseTo(100)
    expect(parseFloat(left!.width)).toBeGreaterThan(11)
    expect(left!.getPropertyValue('--rotation')).toBe('0deg')
  })

  it('moves a number off a card that a later one covers', async () => {
    // Card 2 sits over the middle of card 1, so 1's number goes to an edge.
    const wrapper = await mountPreview([at(1, 50, 50), at(2, 50, 50, 90)])

    const [first, second] = wrapper
      .findAll<HTMLElement>('.preview-number')
      .map((n) => n.element.style)
    expect([second!.left, second!.top]).toEqual(['50%', '50%'])
    expect([first!.left, first!.top]).not.toEqual(['50%', '50%'])
  })

  it('draws a quiet silhouette as a thumbnail: no numbers, no grid, hidden from readers', async () => {
    const wrapper = await mountPreview([at(1, 40, 50), at(2, 60, 50)], true)

    expect(wrapper.findAll('.spread-card')).toHaveLength(2)
    expect(wrapper.find('.preview-number').exists()).toBe(false)
    expect(wrapper.classes()).toContain('spread-canvas--thumb')
    expect(wrapper.classes()).not.toContain('has-grid')
    expect(wrapper.attributes('aria-hidden')).toBe('true')
  })
})
