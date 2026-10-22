import {describe, expect, it, vi} from 'vitest'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {nodes: {branch: {otherwise: 'Otherwise', branch_n: 'Branch :n'}}}}}}),
}))

import {branchAutoName, branchRuleLabel, leftPath} from './branchLabel'
import {nodeHandles} from './nodeHandles'

const sourceLeft = {ref: 'source', source: 'call', field: 'last.body'}

describe('branchRuleLabel', () => {
    it('prefers the trimmed own label', () => {
        expect(branchRuleLabel({label: '  Paid  ', left: sourceLeft, handle: 'u-1'}, 0)).toBe('Paid')
    })

    it('names an unlabelled branch after its condition', () => {
        expect(branchRuleLabel({left: sourceLeft, operator: 'eq', value: 'yes', handle: 'u-1'}, 0)).toBe('call.last.body = yes')
    })

    it('omits the value of unary operators', () => {
        expect(branchAutoName({left: sourceLeft, operator: 'not_empty', value: 'x'})).toBe('call.last.body is not empty')
    })

    it('omits an empty value', () => {
        expect(branchAutoName({left: sourceLeft, operator: 'eq', value: ''})).toBe('call.last.body =')
    })

    it('falls back to "Branch N" without a condition and never shows the handle', () => {
        const label = branchRuleLabel({label: '', left: null, handle: 'f47ac10b-uuid'}, 2)
        expect(label).toBe('Branch 3')
        expect(label).not.toContain('uuid')
    })

    it('uses the translator for the numbered fallback', () => {
        expect(branchRuleLabel({}, 0, () => 'Ветка :n')).toBe('Ветка 1')
    })
})

describe('leftPath', () => {
    it('builds variable paths', () => {
        expect(leftPath({ref: 'user_variable', variable: {name: 'age', storage: 'session', group: null}})).toBe('flow.age')
        expect(leftPath({ref: 'user_variable', variable: {name: 'age', storage: 'contact', group: 'crm'}})).toBe('contact.crm.age')
        expect(leftPath({ref: 'user_variable', variable: {name: 'age', storage: 'contact', group: null}})).toBe('contact.age')
    })

    it('passes legacy string paths and rejects empties', () => {
        expect(leftPath(' call.x ')).toBe('call.x')
        expect(leftPath(null)).toBe('')
        expect(leftPath({ref: 'source', source: '', field: 'a'})).toBe('')
    })
})

describe('nodeHandles for a condition', () => {
    it('labels rule handles by name or condition and ends with Otherwise', () => {
        const handles = nodeHandles({
            id: 'n1',
            type: 'condition',
            config: {
                rules: [
                    {handle: 'u-1', label: 'VIP', left: sourceLeft, operator: 'eq', value: 'a'},
                    {handle: 'u-2', label: '', left: sourceLeft, operator: 'eq', value: 'yes'},
                    {handle: 'u-3', label: '', left: null},
                ],
            },
        })
        expect(handles.map((h) => h.label)).toEqual(['VIP', 'call.last.body = yes', 'Branch 3', 'Otherwise'])
        expect(handles.map((h) => h.handle)).toEqual(['u-1', 'u-2', 'u-3', 'default'])
    })
})
