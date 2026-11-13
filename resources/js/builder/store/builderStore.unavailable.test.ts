import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

import {useBuilderStore} from './builderStore'

// Key order, nested values and unknown keys must come back exactly as stored.
const foreignConfig = {
    zeta: [3, 1, 2],
    prompt: {en: 'How was it?', ru: 'Как прошло?'},
    nested: {b: 1, a: {deep: null, flag: false}},
    unknown_key: 'kept',
}

function initWith(nodes: unknown[]) {
    const store = useBuilderStore()
    store.init({flowId: 'f1', name: 'F', draftVersion: 1, definition: {nodes, edges: []}} as never)

    return store
}

describe('config of unavailable / unknown node types', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('survives load, edits of other nodes and a JSON save round-trip byte for byte', () => {
        const before = JSON.stringify(foreignConfig)
        const store = initWith([
            {id: 'a', type: 'send_message', version: 1, config: {text: 'hi'}},
            {id: 'x', type: 'feedback.collect', version: 3, config: JSON.parse(before)},
            {id: 'e', type: 'end', version: 1, config: {}},
        ])

        store.updateNodeConfig('a', {text: 'changed'} as never)
        store.insertNode('a', 'default', 'delay', 1)

        const saved = JSON.parse(JSON.stringify(store.definition))
        const node = saved.nodes.find((n: {id: string}) => n.id === 'x')

        expect(JSON.stringify(node.config)).toBe(before)
        expect(node.type).toBe('feedback.collect')
        expect(node.version).toBe(3)
    })

    it('keeps the config through undo and redo', () => {
        const before = JSON.stringify(foreignConfig)
        const store = initWith([
            {id: 'x', type: 'gone.node', version: 1, config: JSON.parse(before)},
            {id: 'e', type: 'end', version: 1, config: {}},
        ])

        store.updateNodeConfig('e', {status: 'failed'} as never)
        store.undo()
        store.redo()

        const node = store.definition.nodes.find((n) => n.id === 'x')!
        expect(JSON.stringify(node.config)).toBe(before)
    })
})
