import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { cleanup } from './helpers/app'
import { mountPage, resetPageState, answerConfirm, confirmDialog } from './helpers/page'
import { soon } from '../../../shared/test/web/wait.js'

const MESSAGE = 'Leave this page? Your notes will be lost.'

/** Whether the page has something to lose. */
const dirty = ref(false)

/** A page that guards its notes. */
const Notes = defineComponent({
  setup() {
    useUnsavedChanges(() => dirty.value, MESSAGE)
    return () => h('p', 'Notes')
  },
})

/** On the guarded page, with somewhere else to go. */
async function onNotesPage(): Promise<Router> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/notes', component: Notes },
      { path: '/elsewhere', component: { render: () => h('p', 'Elsewhere') } },
    ],
  })
  await mountPage(RouterView, { router, path: '/notes' })
  return router
}

function reloadIsHeldBack(): boolean {
  const event = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(event)
  return event.defaultPrevented
}

describe('useUnsavedChanges', () => {
  beforeEach(() => {
    resetPageState()
    dirty.value = false
  })
  afterEach(cleanup)

  it('lets a page with nothing to lose go without asking', async () => {
    const router = await onNotesPage()

    await router.push('/elsewhere')

    expect(confirmDialog()).toBeNull()
    expect(router.currentRoute.value.path).toBe('/elsewhere')
  })

  it('asks before unsaved work is left, and stays when told to', async () => {
    const router = await onNotesPage()
    dirty.value = true

    const leaving = router.push('/elsewhere')
    await soon(() => expect(confirmDialog()?.textContent).toContain(MESSAGE))
    expect(confirmDialog()?.textContent).toContain('Unsaved changes')
    await answerConfirm('cancel')
    await leaving

    expect(router.currentRoute.value.path).toBe('/notes')
  })

  it('leaves, dropping the work, when told to', async () => {
    const router = await onNotesPage()
    dirty.value = true

    const leaving = router.push('/elsewhere')
    await soon(() => expect(confirmDialog()).not.toBeNull())
    await answerConfirm('confirm')
    await leaving

    expect(router.currentRoute.value.path).toBe('/elsewhere')
  })

  it('has the browser ask before a reload only while there is work to lose', async () => {
    const router = await onNotesPage()
    expect(reloadIsHeldBack()).toBe(false)

    dirty.value = true
    expect(reloadIsHeldBack()).toBe(true)

    // Gone from the page (its work let go), the question goes with it.
    dirty.value = false
    await router.push('/elsewhere')
    dirty.value = true
    expect(reloadIsHeldBack()).toBe(false)
  })
})
