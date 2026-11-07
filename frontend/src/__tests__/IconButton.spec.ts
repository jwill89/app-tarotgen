import { describe, it, expect, afterEach } from 'vitest'
import { h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import IconButton from '@/components/IconButton.vue'
import { byPrefixAndName } from '@/fontawesome'
import { mountWithApp, cleanup } from './helpers/app'

async function mountButton(props: { disabled?: boolean; loading?: boolean } = {}) {
  // The label is also a tooltip, which needs the provider App.vue mounts.
  const { wrapper } = await mountWithApp(TooltipProvider, {
    slots: {
      default: () =>
        h(IconButton, { icon: byPrefixAndName.fas.trash, label: 'Delete deck', ...props }),
    },
  })
  return { button: wrapper.getComponent(IconButton), element: wrapper.get('button') }
}

describe('IconButton', () => {
  afterEach(cleanup)

  it('is a plain button named by its label', async () => {
    const { element } = await mountButton()

    expect(element.attributes('aria-label')).toBe('Delete deck')
    expect(element.attributes('type')).toBe('button')
  })

  it('reports clicks', async () => {
    const { button, element } = await mountButton()

    await element.trigger('click')

    expect(button.emitted('click')).toHaveLength(1)
  })

  it('shows its label as a tooltip on focus', async () => {
    const { element } = await mountButton()

    await element.trigger('focus')
    await flushPromises()

    expect(document.body.querySelector('[role="tooltip"]')?.textContent.trim()).toBe('Delete deck')
  })

  it('cannot be clicked while disabled or loading', async () => {
    const disabled = await mountButton({ disabled: true })
    expect(disabled.element.attributes('disabled')).toBeDefined()

    const loading = await mountButton({ loading: true })
    expect(loading.element.attributes('disabled')).toBeDefined()
    await loading.element.trigger('click')
    expect(loading.button.emitted('click')).toBeUndefined()
  })
})
