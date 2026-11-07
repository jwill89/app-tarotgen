import { describe, it, expect, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import BaseSelect from '@/components/BaseSelect.vue'
import { mountWithApp, cleanup } from './helpers/app'

const options = [
  { value: 1, label: 'Rider-Waite-Smith' },
  { value: 2, label: 'Thoth' },
  { value: 3, label: 'Marseille', disabled: true },
]

async function mountSelect(modelValue: number | null, props: Record<string, unknown> = {}) {
  const { wrapper } = await mountWithApp(BaseSelect, {
    props: {
      options,
      modelValue,
      ...props,
      'onUpdate:modelValue': (value: string | number | null | undefined): void => {
        void wrapper.setProps({ modelValue: value })
      },
    },
  })
  return { wrapper, trigger: wrapper.get('button[role="combobox"]') }
}

const listedOptions = () =>
  [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].map((o) => ({
    label: o.textContent.trim(),
    disabled: o.hasAttribute('data-disabled'),
    selected: o.getAttribute('aria-selected') === 'true',
  }))

async function open(trigger: Awaited<ReturnType<typeof mountSelect>>['trigger']) {
  await trigger.trigger('keydown', { key: 'Enter' })
  await flushPromises()
}

describe('BaseSelect', () => {
  afterEach(cleanup)

  it('shows the placeholder until something is chosen', async () => {
    const { trigger } = await mountSelect(null, { placeholder: 'Choose a system' })

    expect(trigger.text()).toBe('Choose a system')
  })

  it('falls back to a generic placeholder', async () => {
    const { trigger } = await mountSelect(null)

    expect(trigger.text()).toBe('Select…')
  })

  it("shows the chosen option's label", async () => {
    const { trigger } = await mountSelect(2)

    expect(trigger.text()).toBe('Thoth')
  })

  it('puts the id and name on its trigger, for a label to point at', async () => {
    const { trigger } = await mountSelect(null, { id: 'system-picker', ariaLabel: 'System' })

    expect(trigger.attributes('id')).toBe('system-picker')
    expect(trigger.attributes('aria-label')).toBe('System')
  })

  it('opens a list of the options from the keyboard, marking the chosen one', async () => {
    const { trigger } = await mountSelect(2)

    await open(trigger)

    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(listedOptions()).toEqual([
      { label: 'Rider-Waite-Smith', disabled: false, selected: false },
      { label: 'Thoth', disabled: false, selected: true },
      { label: 'Marseille', disabled: true, selected: false },
    ])
  })

  it('picks the option chosen from the list and closes it', async () => {
    const { wrapper, trigger } = await mountSelect(2)
    await open(trigger)

    const rws = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')][0]!
    rws.focus()
    rws.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toEqual([[1]])
    expect(trigger.text()).toBe('Rider-Waite-Smith')
    expect(document.body.querySelector('[role="listbox"]')).toBeNull()
  })

  it('does not open while disabled', async () => {
    const { trigger } = await mountSelect(1, { disabled: true })

    await open(trigger)

    expect(trigger.attributes('disabled')).toBeDefined()
    expect(document.body.querySelector('[role="listbox"]')).toBeNull()
  })
})
