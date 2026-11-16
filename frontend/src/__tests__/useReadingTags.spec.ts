import { describe, it, expect } from 'vitest'
import { useReadingTags } from '@/composables/useReadingTags'
import { stubApi, problem } from './helpers/app'

describe('useReadingTags', () => {
  it("fetches the reader's tags the first time they are wanted, and only then", async () => {
    const api = stubApi({ 'GET /account/tags': { body: [{ tag: 'career', count: 2 }] } })
    const { tags, load } = useReadingTags()

    expect(api).not.toHaveBeenCalled()
    await Promise.all([load(), load()])
    await load()

    expect(tags.value).toEqual([{ tag: 'career', count: 2 }])
    expect(api).toHaveBeenCalledTimes(1)
  })

  it('offers nothing when they cannot be fetched', async () => {
    stubApi({ 'GET /account/tags': { status: 500, body: problem('Down.') } })
    const { tags, load } = useReadingTags()

    await load()

    expect(tags.value).toEqual([])
  })
})
