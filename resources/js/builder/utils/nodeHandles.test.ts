import {describe, expect, it, vi} from 'vitest'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {}}}}),
}))

import {nodeHandles} from './nodeHandles'
import {nodeMustBeLast} from './nodeTerminal'

describe('nodeHandles for templates', () => {
    it('exposes one handle per snapshot output, labelled by label then name', () => {
        const node = {
            id: 'r1',
            type: 'template',
            config: {template_flow_id: 't', outputs: [{name: 'returned', label: 'Operator returned'}, {name: 'timeout', label: null}]},
        }

        expect(nodeHandles(node)).toEqual([
            {handle: 'returned', label: 'Operator returned'},
            {handle: 'timeout', label: 'timeout'},
        ])
    })

    it('has no handles before a template is chosen', () => {
        expect(nodeHandles({id: 'r1', type: 'template', config: {template_flow_id: ''}})).toEqual([])
    })

    it('gives template_output no outgoing handles', () => {
        expect(nodeHandles({id: 'o1', type: 'template_output', config: {name: 'returned'}})).toEqual([])
    })

    it('treats both template nodes as terminal in the default chain', () => {
        expect(nodeMustBeLast({type: 'template', config: {}})).toBe(true)
        expect(nodeMustBeLast({type: 'template_output', config: {}})).toBe(true)
    })
})
