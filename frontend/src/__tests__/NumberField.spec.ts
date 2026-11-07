import { describe, it, expect, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import NumberField from '@/components/NumberField.vue'
import { mountWithApp, cleanup } from './helpers/app'

async function mountField(modelValue: number, props: Record<string, unknown> = {}) {
  const { wrapper } = await mountWithApp(NumberField, {
    props: {
      modelValue,
      ...props,
      'onUpdate:modelValue': (value: number | undefined): void => {
        void wrapper.setProps({ modelValue: value })
      },
    },
  })
  const input = wrapper.get('input')
  return {
    wrapper,
    input,
    decrease: wrapper.get('[aria-label="Decrease"]'),
    increase: wrapper.get('[aria-label="Increase"]'),
    value: () => (input.element as HTMLInputElement).value,
  }
}

// Reka's steppers act on pointerdown (they auto-repeat while held), not click, and
// on the main button, which is a PointerEvent's default.
const pointer = { pointerType: 'mouse' }

describe('NumberField', () => {
  afterEach(cleanup)

  it('shows the bound number and puts the id and name on the input', async () => {
    const { input, value } = await mountField(3, { id: 'card-count', ariaLabel: 'Cards' })

    expect(value()).toBe('3')
    expect(input.attributes('id')).toBe('card-count')
    expect(input.attributes('aria-label')).toBe('Cards')
  })

  it('steps up and down with the + and − buttons', async () => {
    const { wrapper, increase, decrease, value } = await mountField(3, { min: 1, max: 10 })

    await increase.trigger('pointerdown', pointer)
    await increase.trigger('pointerup', pointer)
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([4])

    await decrease.trigger('pointerdown', pointer)
    await decrease.trigger('pointerup', pointer)
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([3])
    expect(value()).toBe('3')
  })

  it('steps with the arrow keys', async () => {
    const { wrapper, input } = await mountField(4, { step: 2 })

    await input.trigger('keydown', { key: 'ArrowUp' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([6])

    await input.trigger('keydown', { key: 'ArrowDown' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([4])
  })

  it('disables a stepper at its bound', async () => {
    const { increase, decrease } = await mountField(10, { min: 1, max: 10 })

    expect(increase.attributes('disabled')).toBeDefined()
    expect(decrease.attributes('disabled')).toBeUndefined()
  })

  it('clamps a typed number into range when the field is left', async () => {
    const { wrapper, input } = await mountField(3, { min: 1, max: 10 })

    await input.setValue('42')
    await input.trigger('blur')
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([10])
  })

  it('does nothing while disabled', async () => {
    const { wrapper, input, increase } = await mountField(3, { disabled: true })

    expect(input.attributes('disabled')).toBeDefined()
    await increase.trigger('pointerdown', pointer)
    await input.trigger('keydown', { key: 'ArrowUp' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
