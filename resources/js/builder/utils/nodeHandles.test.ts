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

describe('nodeHandles for auth_request', () => {
    it('keeps the single default output for version 1 and for a missing version', () => {
        expect(nodeHandles({id: 'a', type: 'auth_request', version: 1, config: {}}).map((h) => h.handle)).toEqual(['default'])
        expect(nodeHandles({id: 'a', type: 'auth_request', config: {}}).map((h) => h.handle)).toEqual(['default'])
    })

    it('exposes authenticated and failed for version 2', () => {
        expect(nodeHandles({id: 'a', type: 'auth_request', version: 2, config: {method: 'sms_code'}}).map((h) => h.handle))
            .toEqual(['authenticated', 'failed'])
    })
})

describe('nodeHandles for start_flow', () => {
    it('exposes started, busy, no_channel and error in order, and no default one', () => {
        expect(nodeHandles({id: 's1', type: 'start_flow'})).toEqual([
            {handle: 'started', label: 'Started'},
            {handle: 'busy', label: 'Busy'},
            {handle: 'no_channel', label: 'No channel'},
            {handle: 'error', label: 'Error'},
        ])
    })
})
