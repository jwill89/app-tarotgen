import { describe, it, expect, afterEach } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import type { VueWrapper } from '@vue/test-utils'
import ReadingPrivacyField from '@/components/ReadingPrivacyField.vue'
import { mountWithApp, cleanup } from './helpers/app'
import type { Visibility } from '@/types'

async function mountField(start: Visibility = 'link', hasPassword = false) {
  const visibility = ref<Visibility>(start)
  const password = ref('')
  const Host = defineComponent({
    render: () =>
      h(ReadingPrivacyField, {
        passwordId: 'pw',
        hasPassword,
        modelValue: visibility.value,
        'onUpdate:modelValue': (value: Visibility) => {
          visibility.value = value
        },
        password: password.value,
        'onUpdate:password': (value: string) => {
          password.value = value
        },
      }),
  })
  const { wrapper } = await mountWithApp(Host)
  return { wrapper, visibility, password }
}

function choose(wrapper: VueWrapper, label: string) {
  const found = wrapper.findAll('.myst-segmented__btn').find((b) => b.text() === label)
  if (!found) {
    throw new Error(`No "${label}" choice`)
  }
  return found.trigger('click')
}

describe('ReadingPrivacyField', () => {
  afterEach(cleanup)

  it('offers the three choices, saying what the chosen one means', async () => {
    const { wrapper, visibility } = await mountField()

    expect(wrapper.findAll('.myst-segmented__btn').map((b) => b.text())).toEqual([
      'Anyone with the link',
      'Password',
      'Private',
    ])
    expect(wrapper.get('.help').text()).toBe('Anyone with the link can open it.')

    await choose(wrapper, 'Private')
    expect(visibility.value).toBe('private')
    expect(wrapper.get('.help').text()).toBe(
      'Only you can open it. To anyone else the link leads nowhere.',
    )
  })

  it('asks for a new password', async () => {
    const { wrapper, password } = await mountField('password')

    expect(wrapper.get('label[for="pw"]').text()).toBe('View Password')
    expect(wrapper.get('#pw').attributes('placeholder')).toBe('At least 4 characters')
    await wrapper.get('#pw').setValue('abcd')

    expect(password.value).toBe('abcd')
  })

  it('keeps a password the reading already has when the field is left blank', async () => {
    const { wrapper } = await mountField('password', true)

    expect(wrapper.get('label[for="pw"]').text()).toBe('New Password (optional)')
    expect(wrapper.get('#pw').attributes('placeholder')).toBe(
      'Leave blank to keep the current password',
    )
  })

  it('forgets a password typed and then abandoned for another choice', async () => {
    const { wrapper, password } = await mountField('password')
    await wrapper.get('#pw').setValue('abcd')

    await choose(wrapper, 'Anyone with the link')

    expect(password.value).toBe('')
    expect(wrapper.find('#pw').exists()).toBe(false)
  })
})
