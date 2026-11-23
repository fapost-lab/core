// @vitest-environment happy-dom
import {describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'
import {Position} from '@vue-flow/core'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))

import GraphInsertEdge from './GraphInsertEdge.vue'
import GraphInsertButton from './GraphInsertButton.vue'

const NodePalette = {name: 'NodePalette', props: ['afterNodeId', 'handle', 'palettePos'], template: '<div class="palette" />'}

function mountEdge(editable: boolean) {
    setActivePinia(createPinia())

    return mount(GraphInsertEdge, {
        props: {
            id: 'e1', source: 'ask', target: 'next',
            sourceX: 0, sourceY: 0, targetX: 0, targetY: 100,
            sourcePosition: Position.Bottom, targetPosition: Position.Top,
            data: {handle: 'btn-a', editable, title: 'Add block'},
        } as never,
        global: {
            stubs: {
                BaseEdge: true,
                EdgeLabelRenderer: {template: '<div><slot /></div>'},
                Teleport: true,
                NodePalette,
            },
        },
    })
}

describe('GraphInsertEdge', () => {
    it('inserts after the edge source on the edge handle, through the same palette the tree opens', async () => {
        const edge = mountEdge(true)

        expect(edge.findComponent(GraphInsertButton).props()).toMatchObject({afterNodeId: 'ask', handle: 'btn-a'})

        await edge.find('.graph-insert').trigger('click')

        expect(edge.findComponent(NodePalette).props()).toMatchObject({afterNodeId: 'ask', handle: 'btn-a'})
    })

    it('draws no "+" on the edge of a read-only draft', () => {
        expect(mountEdge(false).find('.graph-insert').exists()).toBe(false)
    })
})
