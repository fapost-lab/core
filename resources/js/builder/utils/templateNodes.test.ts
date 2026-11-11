import {describe, expect, it} from 'vitest'
import type {AvailableTemplate, FlowNode, NodeTypePayload} from '@builder/dto/types'
import {
    edgesToDropAfterReselect,
    paletteNodeTypes,
    readTemplateOutputs,
    refreshTemplateSnapshots,
    templateSelectionPatch,
} from './templateNodes'

const template: AvailableTemplate = {
    id: 'tpl-1',
    name: 'Handover',
    version: 2,
    outputs: [{name: 'returned', label: 'Operator returned'}, {name: 'timeout', label: null}],
}

function refNode(config: Record<string, unknown>): FlowNode {
    return {id: 'r1', type: 'template', version: 1, config}
}

describe('refreshTemplateSnapshots', () => {
    it('rewrites a stale outputs snapshot and reports the change', () => {
        const node = refNode({template_flow_id: 'tpl-1', outputs: [{name: 'old', label: null}]})

        expect(refreshTemplateSnapshots([node], [template])).toBe(true)
        expect(node.config.outputs).toEqual(template.outputs)
        expect(node.config.template_flow_id).toBe('tpl-1')
    })

    it('reports no change when the snapshot already matches', () => {
        const node = refNode({template_flow_id: 'tpl-1', outputs: template.outputs.map((o) => ({...o}))})

        expect(refreshTemplateSnapshots([node], [template])).toBe(false)
    })

    it('fills a missing snapshot', () => {
        const node = refNode({template_flow_id: 'tpl-1'})

        expect(refreshTemplateSnapshots([node], [template])).toBe(true)
        expect(node.config.outputs).toEqual(template.outputs)
    })

    it('leaves nodes of an unavailable template and other node types alone', () => {
        const orphan = refNode({template_flow_id: 'gone', outputs: [{name: 'x', label: null}]})
        const other: FlowNode = {id: 'e', type: 'end', version: 1, config: {}}

        expect(refreshTemplateSnapshots([orphan, other], [template])).toBe(false)
        expect(orphan.config.outputs).toEqual([{name: 'x', label: null}])
    })
})

describe('template selection', () => {
    it('builds the config patch from the template outputs', () => {
        expect(templateSelectionPatch(template)).toEqual({template_flow_id: 'tpl-1', outputs: template.outputs})
    })

    it('finds edges whose handle the new template does not have, and only for that node', () => {
        const edges = [
            {id: 'a', from: 'r1', to: 'x', handle: 'returned'},
            {id: 'b', from: 'r1', to: 'y', handle: 'gone'},
            {id: 'c', from: 'other', to: 'z', handle: 'gone'},
        ]

        expect(edgesToDropAfterReselect(edges, 'r1', template.outputs).map((e) => e.id)).toEqual(['b'])
    })

    it('ignores malformed outputs', () => {
        expect(readTemplateOutputs({outputs: [{name: ''}, null, {name: 'ok', label: ''}, 'x']})).toEqual([{name: 'ok', label: null}])
        expect(readTemplateOutputs(null)).toEqual([])
    })
})

describe('paletteNodeTypes', () => {
    const types = [
        {type: 'send_message', version: 1, label: 'Send', category: 'Core', config_schema: {}},
        {type: 'template', version: 1, label: 'Template', category: 'Logic', config_schema: {}},
        {type: 'template_output', version: 1, label: 'Template output', category: 'Logic', config_schema: {}},
        {type: 'loop_end', version: 1, label: 'Loop end', category: 'Logic', config_schema: {}, palette: false},
    ] as NodeTypePayload[]

    it('offers Template but not Template output in a flow', () => {
        expect(paletteNodeTypes(types, 'flow').map((t) => t.type)).toEqual(['send_message', 'template'])
    })

    it('offers Template output but not Template (no nesting) in a template', () => {
        expect(paletteNodeTypes(types, 'template').map((t) => t.type)).toEqual(['send_message', 'template_output'])
    })
})
