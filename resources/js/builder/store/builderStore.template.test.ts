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
