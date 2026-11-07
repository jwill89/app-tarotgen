import { describe, it, expect, afterEach } from 'vitest'
import { h } from 'vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import { mountWithApp, cleanup } from './helpers/app'

interface Sort {
  activeKey: string | null
  dir: 'asc' | 'desc'
}

// A <th> only parses inside a table row.
async function mountHeader(sort: Sort) {
  const { wrapper } = await mountWithApp({
    render: () =>
      h('table', [
        h('thead', [h('tr', [h(SortableTh, { label: 'Name', sortKey: 'name', ...sort })])]),
      ]),
  })
  return { header: wrapper.getComponent(SortableTh), th: wrapper.get('th') }
}

describe('SortableTh', () => {
  afterEach(cleanup)

  it('asks to sort by its column when clicked', async () => {
    const { header, th } = await mountHeader({ activeKey: null, dir: 'asc' })

    await th.trigger('click')

    expect(header.emitted('sort')).toEqual([['name']])
  })

  it('can be reached and used from the keyboard', async () => {
    const { header, th } = await mountHeader({ activeKey: null, dir: 'asc' })
    const button = th.get('button')

    expect(button.text()).toBe('Name')
    expect(button.attributes('type')).toBe('button')
    // A button's Enter/Space activation is a click, which the cell handles.
    await button.trigger('click')

    expect(header.emitted('sort')).toEqual([['name']])
  })

  it('says which way the table is sorted when it is the sorted column', async () => {
    const ascending = await mountHeader({ activeKey: 'name', dir: 'asc' })
    expect(ascending.th.attributes('aria-sort')).toBe('ascending')

    const descending = await mountHeader({ activeKey: 'name', dir: 'desc' })
    expect(descending.th.attributes('aria-sort')).toBe('descending')
  })

  it('claims no sort order while another column is sorted', async () => {
    const { th } = await mountHeader({ activeKey: 'email', dir: 'asc' })

    expect(th.attributes('aria-sort')).toBeUndefined()
  })
})
