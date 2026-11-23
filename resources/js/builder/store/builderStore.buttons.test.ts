import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

import {useBuilderStore} from './builderStore'

type Edge = {id: string; from: string; to: string; handle?: string}

function load(type: string, buttons: Array<{id: string}>, edges: Edge[]) {
    const store = useBuilderStore()
    store.init({
        flowId: 'f1', name: 'F', draftVersion: 1, readOnly: false, triggers: [],
        definition: {
            nodes: [
                {id: 'q', type, version: 1, config: {content_type: 'text_with_keyboard', buttons}},
                ...['y', 'n', 't', 'x', 'd'].map((id) => ({id, type: 'set_tag', version: 1, config: {}})),
            ],
            edges,
        },
    } as never)

    return store
}

function handlesFrom(store: ReturnType<typeof useBuilderStore>, nodeId: string): string[] {
    return store.definition.edges.filter((edge) => edge.from === nodeId).map((edge) => edge.handle ?? 'default').sort()
}

describe('updateNodeConfig prunes the edges of removed buttons', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('drops a removed ask_person answer and keeps its timeout, error and default exits', () => {
        const store = load('ask_person', [{id: 'yes'}, {id: 'no'}], [
            {id: 'e1', from: 'q', to: 'y', handle: 'yes'},
            {id: 'e2', from: 'q', to: 'n', handle: 'no'},
            {id: 'e3', from: 'q', to: 't', handle: 'timeout'},
            {id: 'e4', from: 'q', to: 'x', handle: 'error'},
            {id: 'e5', from: 'q', to: 'd'},
        ])

        store.updateNodeConfig('q', {buttons: [{id: 'yes'}]} as never)

        expect(handlesFrom(store, 'q')).toEqual(['default', 'error', 'timeout', 'yes'])
    })

    it('drops a removed send_message button and keeps only its default exit besides the buttons', () => {
        const store = load('send_message', [{id: 'yes'}, {id: 'no'}], [
            {id: 'e1', from: 'q', to: 'y', handle: 'yes'},
            {id: 'e2', from: 'q', to: 'n', handle: 'no'},
            {id: 'e3', from: 'q', to: 't', handle: 'timeout'},
        ])

        store.updateNodeConfig('q', {buttons: [{id: 'yes'}]} as never)

        expect(handlesFrom(store, 'q')).toEqual(['yes'])
    })

    it('leaves the edges alone when the patch does not touch the buttons', () => {
        const store = load('ask_person', [{id: 'yes'}], [{id: 'e1', from: 'q', to: 'y', handle: 'yes'}, {id: 'e2', from: 'q', to: 'n', handle: 'gone'}])

        store.updateNodeConfig('q', {prompt: 'Approve?'} as never)

        expect(handlesFrom(store, 'q')).toEqual(['gone', 'yes'])
    })
})
