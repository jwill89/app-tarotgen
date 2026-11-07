import { describe, it, expect, afterEach } from 'vitest'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { byPrefixAndName } from '@/fontawesome'
import { mountWithApp, cleanup } from './helpers/app'

const options = [
  { value: 'spread', label: 'Spread', icon: byPrefixAndName.fas['table-cells'] },
  { value: 'free', label: 'Free Draw' },
  { value: 'custom', label: 'Custom' },
]

async function mountControl(modelValue = 'spread') {
  const { wrapper } = await mountWithApp(SegmentedControl, {
    props: {
      options,
      modelValue,
      ariaLabel: 'Reading type',
      'onUpdate:modelValue': (value: string): void => {
        void wrapper.setProps({ modelValue: value })
      },
    },
  })
  const segment = (label: string) => {
    const found = wrapper.findAll('button').find((b) => b.text() === label)
    if (!found) {
      throw new Error(`No segment "${label}"`)
    }
    return found
  }
  return { wrapper, segment }
}

describe('SegmentedControl', () => {
  afterEach(cleanup)

  it('is a named group of toggle buttons with the current one pressed', async () => {
    const { wrapper, segment } = await mountControl('free')

    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Reading type')
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual([
      'Spread',
      'Free Draw',
      'Custom',
    ])
    expect(segment('Free Draw').attributes('aria-pressed')).toBe('true')
    expect(segment('Spread').attributes('aria-pressed')).toBe('false')
  })

  it('selects the option that is clicked', async () => {
    const { wrapper, segment } = await mountControl('spread')

    await segment('Custom').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['custom']])
    expect(segment('Custom').attributes('aria-pressed')).toBe('true')
  })

  it('keeps the current option when it is clicked again, never leaving none selected', async () => {
    const { wrapper, segment } = await mountControl('spread')

    await segment('Spread').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(segment('Spread').attributes('aria-pressed')).toBe('true')
  })

  it('moves between options with the arrow keys', async () => {
    const { segment } = await mountControl('spread')
    const first = segment('Spread')
    ;(first.element as HTMLElement).focus()

    await first.trigger('keydown', { key: 'ArrowRight' })

    expect(document.activeElement).toBe(segment('Free Draw').element)
  })
})
