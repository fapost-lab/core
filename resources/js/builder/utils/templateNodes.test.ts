import {describe, expect, it} from 'vitest'
import rules from '../../../../tests/Fixtures/flow-template-parameter-rules.json'
import type {AvailableTemplate, FlowNode, NodeTypePayload} from '@builder/dto/types'
import {
    EXPOSABLE_FIELD_TYPES,
    NON_PARAMETERIZABLE_NODE_TYPES,
    PARAMETER_KEY_PATTERN,
    edgesToDropAfterReselect,
    exposableFields,
    isEmptyParameterValue,
    nextParameterValues,
    withBaseLanguageText,
    parameterValueText,
    readTemplateParameters,
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
        expect(templateSelectionPatch(template)).toEqual({template_flow_id: 'tpl-1', outputs: template.outputs, parameters: []})
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

const param = {key: 'greeting', label: 'Greeting', node_type: 'send_message', node_version: 1, field: 'text'}
const withParams: AvailableTemplate = {...template, parameters: [{...param, default: {en: 'Hi'}}]}

describe('template parameters snapshot', () => {
    it('normalises key order and drops malformed entries', () => {
        const parsed = readTemplateParameters({parameters: [
            {field: 'text', node_version: 1, node_type: 'send_message', label: '', key: 'greeting'},
            {key: '', node_type: 'x', field: 'y'},
            null,
        ]})

        expect(parsed).toEqual([{key: 'greeting', label: null, node_type: 'send_message', node_version: 1, field: 'text'}])
        expect(Object.keys(parsed[0])).toEqual(['key', 'label', 'node_type', 'node_version', 'field'])
    })

    it('never writes the default into the selection patch', () => {
        const patch = templateSelectionPatch(withParams)

        expect(patch.parameters).toEqual([param])
        expect(Object.keys(patch.parameters[0])).toEqual(['key', 'label', 'node_type', 'node_version', 'field'])
    })

    it('refreshes a missing parameters snapshot once, then stays clean', () => {
        const node = refNode({template_flow_id: 'tpl-1', outputs: template.outputs.map((o) => ({...o}))})

        expect(refreshTemplateSnapshots([node], [withParams])).toBe(true)
        expect(node.config.parameters).toEqual([param])
        expect(refreshTemplateSnapshots([node], [withParams])).toBe(false)
    })

    it('is not dirtied by jsonb key re-ordering', () => {
        const node = refNode({
            template_flow_id: 'tpl-1',
            outputs: template.outputs.map((o) => ({...o})),
            parameters: [{field: 'text', node_version: 1, node_type: 'send_message', label: 'Greeting', key: 'greeting'}],
        })

        expect(refreshTemplateSnapshots([node], [withParams])).toBe(false)
    })

    it('reports a change when the template drops a parameter', () => {
        const node = refNode({template_flow_id: 'tpl-1', outputs: template.outputs.map((o) => ({...o})), parameters: [param]})

        expect(refreshTemplateSnapshots([node], [template])).toBe(true)
        expect(node.config.parameters).toEqual([])
    })
})

describe('isEmptyParameterValue', () => {
    it.each([undefined, null, '', '   ', [], {}, {en: ''}, {en: '  ', ru: ''}])('%j is empty', (v) => {
        expect(isEmptyParameterValue(v)).toBe(true)
    })

    it.each([false, 0, 'x', [1], {en: 'Hi'}, {en: '', ru: 'Привет'}])('%j is a value', (v) => {
        expect(isEmptyParameterValue(v)).toBe(false)
    })
})

describe('exposableFields', () => {
    const schema = {
        default_config: {},
        text: {type: 'text', label: 'Message text'},
        mode: {type: 'enum'},
        buttons: {type: 'object-array'},
        meta: {type: 'json'},
        pairs: {type: 'key-value'},
        nested: {type: 'object'},
        wait: {type: 'duration', label: 'Wait'},
    }

    it('keeps only exposable types with a label fallback', () => {
        expect(exposableFields(schema, 'send_message')).toEqual([
            {key: 'text', label: 'Message text', type: 'text'},
            {key: 'mode', label: 'mode', type: 'enum'},
            {key: 'wait', label: 'Wait', type: 'duration'},
        ])
    })

    it.each(['template', 'template_output', 'loop', 'loop_end'])('exposes nothing on %s', (type) => {
        expect(exposableFields(schema, type)).toEqual([])
    })
})

describe('parameterValueText', () => {
    it('renders the base language of a map, else the first filled one', () => {
        expect(parameterValueText({en: 'Hi', ru: 'Привет'}, 'ru')).toBe('Привет')
        expect(parameterValueText({en: '', ru: 'Привет'}, 'en')).toBe('Привет')
        expect(parameterValueText(undefined, 'en')).toBe('')
        expect(parameterValueText(false, 'en')).toBe('false')
        expect(parameterValueText(['a', 'b'], 'en')).toBe('a, b')
    })
})

describe('parameter values set in the reference panel', () => {
    it('keeps a cleared base language next to a translation, so publishing fills it from the template', () => {
        const value = withBaseLanguageText({en: 'Custom', uk: 'Своє'}, 'en', '')
        expect(value).toEqual({en: '', uk: 'Своє'})
        expect(nextParameterValues({searching: {en: 'Custom', uk: 'Своє'}}, 'searching', value))
            .toEqual({searching: {en: '', uk: 'Своє'}})
    })

    it('drops a value whose only language is cleared, so the template text stays', () => {
        const value = withBaseLanguageText({en: 'Custom'}, 'en', '  ')
        expect(nextParameterValues({searching: {en: 'Custom'}, other: 1}, 'searching', value)).toEqual({other: 1})
        expect(nextParameterValues({searching: {en: 'Custom'}}, 'searching', value)).toBeUndefined()
    })

    it('keeps false and 0 as values', () => {
        expect(nextParameterValues({}, 'flag', false)).toEqual({flag: false})
        expect(nextParameterValues({}, 'wait', 0)).toEqual({wait: 0})
    })
})

describe('parity with the server rules (tests/Fixtures/flow-template-parameter-rules.json)', () => {
    it('exposes the same field types and blocks the same node types', () => {
        expect(EXPOSABLE_FIELD_TYPES).toEqual(rules.exposable_field_types)
        expect(NON_PARAMETERIZABLE_NODE_TYPES).toEqual(rules.non_parameterizable_node_types)
    })

    it('uses the same key pattern', () => {
        expect(PARAMETER_KEY_PATTERN.source).toBe(rules.parameter_key_pattern)
    })

    it('judges every fixture value like the server', () => {
        for (const value of rules.empty_value_cases.empty) {
            expect(isEmptyParameterValue(value), JSON.stringify(value)).toBe(true)
        }
        for (const value of rules.empty_value_cases.not_empty) {
            expect(isEmptyParameterValue(value), JSON.stringify(value)).toBe(false)
        }
    })
})
