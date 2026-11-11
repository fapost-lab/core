import {beforeEach, describe, expect, it, vi} from 'vitest'
import {nextTick} from 'vue'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

import {useBuilderStore} from './builderStore'

const tpl = {id: 'tpl-1', name: 'Handover', version: 1, outputs: [{name: 'returned', label: null}]}

function flow(extra: Record<string, unknown>) {
    return {flowId: 'f1', name: 'F', draftVersion: 1, ...extra} as never
}

describe('builder store with templates', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('refreshes a stale snapshot on init and flags the draft dirty', () => {
        const store = useBuilderStore()
        store.init(flow({
            availableTemplates: [tpl],
            definition: {
                nodes: [
                    {id: 'r', type: 'template', version: 1, config: {template_flow_id: 'tpl-1', outputs: []}},
                    {id: 'e', type: 'end', version: 1, config: {}},
                ],
                edges: [],
            },
        }))

        expect(store.definition.nodes[0].config.outputs).toEqual(tpl.outputs)
        expect(store.isDirty).toBe(true)
    })

    it('does not append an End to a template draft', () => {
        const store = useBuilderStore()
        store.init(flow({kind: 'template', definition: {nodes: [], edges: []}}))

        expect(store.isTemplate).toBe(true)
        expect(store.definition.nodes).toEqual([])
        expect(store.ensureBranchEnd('x', 'default')).toBe(false)
    })

    it('drops edges of vanished outputs when another template is selected', async () => {
        const other = {id: 'tpl-2', name: 'Other', version: 1, outputs: [{name: 'done', label: null}]}
        const store = useBuilderStore()
        store.init(flow({
            availableTemplates: [tpl, other],
            definition: {
                nodes: [
                    {id: 'r', type: 'template', version: 1, config: {template_flow_id: 'tpl-1', outputs: tpl.outputs}},
                    {id: 'e', type: 'end', version: 1, config: {}},
                ],
                edges: [{id: 'x', from: 'r', to: 'e', handle: 'returned'}],
            },
        }))
        await nextTick()

        store.selectTemplate('r', 'tpl-2')

        expect(store.definition.edges).toEqual([])
        expect(store.definition.nodes[0].config).toMatchObject({template_flow_id: 'tpl-2', outputs: other.outputs})
    })
})

describe('builder store snapshot refresh', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('keeps the draft clean when the snapshot already matches', async () => {
        const store = useBuilderStore()
        store.init(flow({
            availableTemplates: [tpl],
            definition: {
                nodes: [
                    {id: 'r', type: 'template', version: 1, config: {template_flow_id: 'tpl-1', outputs: tpl.outputs}},
                    {id: 'e', type: 'end', version: 1, config: {}},
                ],
                edges: [{id: 'x', from: 'r', to: 'e', handle: 'returned'}],
            },
        }))
        await nextTick()

        expect(store.isDirty).toBe(false)
    })
})

describe('builder store template parameters', () => {
    beforeEach(() => setActivePinia(createPinia()))

    const other = {id: 'tpl-2', name: 'Other', version: 1, outputs: tpl.outputs}

    function init(templateId: string) {
        const store = useBuilderStore()
        store.init(flow({
            availableTemplates: [tpl, other],
            definition: {
                nodes: [
                    {
                        id: 'r', type: 'template', version: 1,
                        config: {template_flow_id: templateId, outputs: tpl.outputs, parameters: [], parameter_values: {greeting: {en: 'Hi'}}},
                    },
                    {id: 'e', type: 'end', version: 1, config: {}},
                ],
                edges: [],
            },
        }))
        return store
    }

    it('clears parameter_values when another template is selected', () => {
        const store = init('tpl-1')

        store.selectTemplate('r', 'tpl-2')

        expect(store.definition.nodes[0].config).not.toHaveProperty('parameter_values')
    })

    it('keeps parameter_values when the same template is reselected', () => {
        const store = init('tpl-1')

        store.selectTemplate('r', 'tpl-1')

        expect(store.definition.nodes[0].config.parameter_values).toEqual({greeting: {en: 'Hi'}})
    })

    it('sets and removes the node-level parameters declaration with undo', () => {
        const store = useBuilderStore()
        store.init(flow({kind: 'template', definition: {nodes: [{id: 'n', type: 'send_message', version: 1, config: {}}], edges: []}}))

        store.setNodeParameters('n', [{key: 'greeting', label: null, field: 'text'}])
        expect(store.definition.nodes[0].parameters).toEqual([{key: 'greeting', label: null, field: 'text'}])
        expect(store.definition.nodes[0].config).not.toHaveProperty('parameters')

        store.setNodeParameters('n', [])
        expect(store.definition.nodes[0]).not.toHaveProperty('parameters')

        store.undo()
        expect(store.definition.nodes[0].parameters).toHaveLength(1)
    })
})
