import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'
import {nextTick} from 'vue'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

const saveBuilderMode = vi.fn()

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@builder/api/builderApi')>()),
    saveBuilderMode: (...args: unknown[]) => saveBuilderMode(...args),
}))

import {useBuilderStore} from './builderStore'

function load(payload: Record<string, unknown> = {}) {
    const store = useBuilderStore()
    store.init({
        flowId: 'f1', name: 'F', draftVersion: 1,
        definition: {nodes: [{id: 'e', type: 'end', version: 1, config: {}}], edges: []},
        ...payload,
    } as never)

    return store
}

describe('builder mode', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        saveBuilderMode.mockReset()
        saveBuilderMode.mockResolvedValue({builder_mode: 'graph'})
    })

    it('opens in the mode the payload carries, the tree when it carries none', () => {
        expect(load().builderMode).toBe('tree')

        setActivePinia(createPinia())
        expect(load({builderMode: 'graph'}).builderMode).toBe('graph')
    })

    it('switches at once and stores the choice for the person', async () => {
        const store = load()

        const saving = store.setBuilderMode('graph')

        expect(store.builderMode).toBe('graph')
        await saving
        expect(saveBuilderMode).toHaveBeenCalledWith('graph')
    })

    it('does not store a mode that is already the current one', async () => {
        const store = load({builderMode: 'graph'})

        await store.setBuilderMode('graph')

        expect(saveBuilderMode).not.toHaveBeenCalled()
    })

    it('keeps the switch when the server refuses to store it, and leaves the draft clean', async () => {
        saveBuilderMode.mockRejectedValue(Object.assign(new Error('stopped'), {status: 423}))
        const store = load({readOnly: true})
        await nextTick()

        await store.setBuilderMode('graph')
        await nextTick()

        expect(store.builderMode).toBe('graph')
        expect(store.isDirty).toBe(false)
        expect(store.undoStack).toHaveLength(0)
    })
})
