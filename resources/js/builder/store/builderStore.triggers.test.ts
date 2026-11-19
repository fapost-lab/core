import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'
import {nextTick} from 'vue'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

const saveDraft = vi.fn()
const validateFlow = vi.fn()

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@builder/api/builderApi')>()),
    saveDraft: (...args: unknown[]) => saveDraft(...args),
    validateFlow: (...args: unknown[]) => validateFlow(...args),
}))

import {useBuilderStore} from './builderStore'
import {useSelectionStore} from './selectionStore'
import {useAutoSave} from '@builder/composables/useAutoSave'
import {useValidation} from '@builder/composables/useValidation'

const definition = {
    nodes: [{id: 'a', type: 'send_message', version: 1, config: {text: 'hi'}}, {id: 'e', type: 'end', version: 1, config: {}}],
    edges: [{id: 'x', from: 'a', to: 'e', handle: 'default'}],
}

function load(triggers: unknown) {
    const store = useBuilderStore()
    store.init({flowId: 'f1', name: 'F', draftVersion: 3, readOnly: false, definition, triggers} as never)

    return store
}

describe('builder triggers', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        saveDraft.mockReset()
        validateFlow.mockReset()
        vi.useFakeTimers()
    })

    it('normalizes the loaded triggers and drops entries without an id or type', () => {
        const store = load([
            {id: 't1', type: 'event', config: {event_name: 'x'}},
            {type: 'message'},
            {id: 't3'},
            {id: 't4', type: 'api', is_active: false, priority: 7, config: {route_key: 'k'}},
        ])

        expect(store.triggers).toEqual([
            {id: 't1', type: 'event', is_active: true, priority: 100, config: {event_name: 'x'}},
            {id: 't4', type: 'api', is_active: false, priority: 7, config: {route_key: 'k'}},
        ])
    })

    it('treats a missing triggers prop as no triggers', () => {
        expect(load(undefined).triggers).toEqual([])
    })

    it('adds triggers with fresh ids and defaults', () => {
        const store = load([])
        const first = store.addTrigger()
        const second = store.addTrigger('schedule')

        expect(first).not.toBe(second)
        expect(store.triggers).toEqual([
            {id: first, type: 'message', is_active: true, priority: 100, config: {keywords: [], phrases: []}},
            {id: second, type: 'schedule', is_active: true, priority: 100, config: {}},
        ])
    })

    it('updates one trigger by id, merging its config', () => {
        const store = load([
            {id: 't1', type: 'message', is_active: true, priority: 100, config: {keywords: ['a'], phrases: []}},
            {id: 't2', type: 'message', is_active: true, priority: 100, config: {keywords: ['b'], phrases: []}},
        ])

        store.updateTrigger('t1', {priority: 5, config: {phrases: ['p']}, id: 'hijack'})

        expect(store.triggers[0]).toEqual({id: 't1', type: 'message', is_active: true, priority: 5, config: {keywords: ['a'], phrases: ['p']}})
        expect(store.triggers[1].priority).toBe(100)

        store.updateTrigger('t1', {type: 'event', config: {event_name: ''}})
        expect(store.triggers[0].config).toEqual({event_name: ''})
    })

    it('deletes a trigger by removing it from the list', () => {
        const store = load([
            {id: 't1', type: 'message', config: {}},
            {id: 't2', type: 'api', config: {}},
        ])

        store.deleteTrigger('t1')

        expect(store.triggers.map((trigger) => trigger.id)).toEqual(['t2'])
    })

    it('marks the draft dirty on a trigger change and sends the full triggers array on save', async () => {
        saveDraft.mockResolvedValue({draft_version: 4})
        const store = load([{id: 't1', type: 'message', config: {}}])
        const {save} = useAutoSave()
        await nextTick()
        expect(store.isDirty).toBe(false)

        store.deleteTrigger('t1')
        await nextTick()
        expect(store.isDirty).toBe(true)

        void save()
        await vi.advanceTimersByTimeAsync(3000)

        expect(saveDraft).toHaveBeenCalledWith('f1', store.definition, [], 3)
    })

    it('sends the triggers with a validate request', async () => {
        validateFlow.mockResolvedValue({valid: true, errors: []})
        const store = load([{id: 't1', type: 'api', config: {route_key: 'k'}}])

        await useValidation().validate()

        expect(validateFlow).toHaveBeenCalledWith('f1', store.definition, store.triggers)
    })

    it('selects a trigger by id and toggles it off', () => {
        const selection = useSelectionStore()

        selection.selectTrigger('t1')
        expect(selection.selectedTriggerId).toBe('t1')
        expect(selection.selectedNodeId).toBe('__trigger__:t1')

        selection.selectTrigger('t2')
        expect(selection.selectedTriggerId).toBe('t2')

        selection.selectTrigger('t2')
        expect(selection.selectedTriggerId).toBeNull()

        selection.select('a')
        expect(selection.selectedTriggerId).toBeNull()
    })
})
