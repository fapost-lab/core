import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'
import {nextTick} from 'vue'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

const saveDraft = vi.fn()

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@builder/api/builderApi')>()),
    saveDraft: (...args: unknown[]) => saveDraft(...args),
}))

import {useBuilderStore} from './builderStore'
import {useAutoSave} from '@builder/composables/useAutoSave'

function load(readOnly: boolean) {
    const store = useBuilderStore()
    store.init({
        flowId: 'f1', name: 'F', draftVersion: 1, readOnly,
        definition: {nodes: [{id: 'a', type: 'send_message', version: 1, config: {text: 'hi'}}, {id: 'e', type: 'end', version: 1, config: {}}], edges: [{id: 'x', from: 'a', to: 'e', handle: 'default'}]},
    } as never)

    return store
}

describe('view-only builder', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        saveDraft.mockReset()
        vi.useFakeTimers()
    })

    it('takes the flag from the payload and refuses every edit', () => {
        const store = load(true)
        const before = JSON.stringify(store.definition)

        store.updateNodeConfig('a', {text: 'changed'} as never)
        expect(store.insertNode('a', 'default', 'delay', 1)).toBeNull()
        expect(store.deleteNode('a')).toBe(false)
        store.moveNodeDown('a')
        store.deleteTrigger()

        expect(store.readOnly).toBe(true)
        expect(store.trigger).toBeNull()
        expect(JSON.stringify(store.definition)).toBe(before)
    })

    it('sends no save request', async () => {
        load(true)
        const {save} = useAutoSave()

        await nextTick()
        void save()
        await vi.advanceTimersByTimeAsync(3000)

        expect(saveDraft).not.toHaveBeenCalled()
    })

    it('stays editable when the flag is off', () => {
        const store = load(false)

        store.updateNodeConfig('a', {text: 'changed'} as never)

        expect(store.readOnly).toBe(false)
        expect((store.definition.nodes[0].config as {text: string}).text).toBe('changed')
    })

    it('flips to view-only on a 423 tenant_stopped answer and does not show the generic error', async () => {
        const notice = {title: 'Trial ended', message: null, actionLabel: null, actionUrl: null}
        saveDraft.mockRejectedValueOnce(Object.assign(new Error('Locked'), {status: 423, body: {error: 'tenant_stopped', notice}}))
        const store = load(false)
        const {save} = useAutoSave()

        await nextTick()
        store.updateNodeConfig('a', {text: 'changed'} as never)
        await nextTick()
        void save()
        await vi.advanceTimersByTimeAsync(3000)

        expect(saveDraft).toHaveBeenCalledTimes(1)
        expect(store.readOnly).toBe(true)
        expect(store.readOnlyNotice).toEqual(notice)
        expect(store.saveStatus).toBe('idle')

        store.updateNodeConfig('a', {text: 'again'} as never)
        await vi.advanceTimersByTimeAsync(3000)
        expect(saveDraft).toHaveBeenCalledTimes(1)
    })
})
