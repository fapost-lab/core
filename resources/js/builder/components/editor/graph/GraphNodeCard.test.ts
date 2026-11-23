// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {flushPromises, mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {graph: {delete: 'Delete node', move: 'Move', add_after: 'Add on :handle'}}}}}),
}))

import GraphNodeCard from './GraphNodeCard.vue'
import {drawDefinitionGraph, type GraphNodeBox} from './definitionGraph'
import {nodeHandles} from '@builder/utils/nodeHandles'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import {useConfirm} from '@builder/composables/useConfirm'
import type {FlowDefinition} from '@builder/dto/types'

const definition: FlowDefinition = {
    nodes: [
        {id: 'ask', type: 'send_message', version: 1, config: {content_type: 'text_with_keyboard', buttons: [{id: 'b1', label: 'Yes'}]}},
        {id: 'done', type: 'end', version: 1, config: {}},
    ],
    edges: [],
}

function load(readOnly: boolean): GraphNodeBox {
    const builder = useBuilderStore()
    builder.init({flowId: 'f1', name: 'F', draftVersion: 1, readOnly, definition} as never)

    return drawDefinitionGraph(builder.definition, nodeHandles).boxes.find((box): box is GraphNodeBox => box.kind === 'node' && box.id === 'ask')!
}

function mountCard(box: GraphNodeBox) {
    return mount(GraphNodeCard, {
        props: {data: {box}},
        global: {stubs: {Handle: true, NodeIcon: true, Teleport: true}},
    })
}

describe('GraphNodeCard', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('selects its node for the config panel on a click', async () => {
        const card = mountCard(load(false))

        await card.trigger('click')

        expect(useSelectionStore().selectedNodeId).toBe('ask')
    })

    it('offers delete, move and a "+" on each free handle in an editable draft', async () => {
        const card = mountCard(load(false))

        expect(card.find('[aria-label="Delete node"]').exists()).toBe(true)
        expect(card.find('[aria-label="Move"]').exists()).toBe(true)
        expect(card.findAll('.graph-insert').map((button) => button.attributes('aria-label'))).toEqual(['Add on Yes'])

        await card.find('[aria-label="Delete node"]').trigger('click')
        // A node with no branch to rescue is deleted straight away, as from the tree's card.
        expect(useDeleteNode().targetNodeId.value).toBeNull()
        expect(useBuilderStore().definition.nodes.map((node) => node.id)).not.toContain('ask')
    })

    it('offers no change in a read-only draft', () => {
        const card = mountCard(load(true))

        expect(card.find('[aria-label="Delete node"]').exists()).toBe(false)
        expect(card.find('[aria-label="Move"]').exists()).toBe(false)
        expect(card.findAll('.graph-insert')).toHaveLength(0)
    })
})

describe('GraphNodeCard for a loop', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('asks before deleting a loop with a body, and keeps it on a no', async () => {
        const builder = useBuilderStore()
        builder.init({
            flowId: 'f1', name: 'F', draftVersion: 1,
            definition: {
                nodes: [
                    {id: 'loop', type: 'loop', version: 1, config: {}},
                    {id: 'body', type: 'delay', version: 1, config: {}},
                    {id: 'loop-end', type: 'loop_end', version: 1, config: {loop_node_id: 'loop'}},
                ],
                edges: [
                    {id: 'e1', from: 'loop', to: 'body', handle: 'loop'},
                    {id: 'e2', from: 'body', to: 'loop-end', handle: 'default'},
                ],
            },
        } as never)
        const box = drawDefinitionGraph(builder.definition, nodeHandles).boxes.find((b): b is GraphNodeBox => b.kind === 'node' && b.id === 'loop')!
        const card = mountCard(box)
        const {active, resolveActive} = useConfirm()

        await card.find('[aria-label="Delete node"]').trigger('click')

        expect(active.value?.message).toBeTruthy()
        resolveActive(false)
        await flushPromises()
        expect(builder.definition.nodes.map((node) => node.id)).toContain('body')
    })
})
