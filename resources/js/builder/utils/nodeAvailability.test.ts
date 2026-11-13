import {describe, expect, it} from 'vitest'
import {fallbackTypeLabel, isNodeUnavailable, nodeAvailability, unavailableReason} from './nodeAvailability'
import {nodeColors} from './nodeColors'

const meta = (over: Record<string, unknown> = {}) => ({
    type: 'feedback.collect', version: 1, label: 'Collect', category: 'feedback', config_schema: {}, ...over,
}) as never

const t = (key: string) => ({
    'nodes.unavailable.solution_off': 'Solution :name is turned off',
    'nodes.unavailable.solution_missing': 'Solution :id is not installed',
}[key] ?? key)

describe('nodeAvailability', () => {
    it('treats Core and old-response metadata as available', () => {
        expect(nodeAvailability('send_message', meta({type: 'send_message'})).state).toBe('available')
        expect(nodeAvailability('feedback.collect', meta({available: true})).state).toBe('available')
        expect(nodeAvailability('mystery', undefined).state).toBe('available')
    })

    it('flags a turned-off Solution with its name', () => {
        const a = nodeAvailability('feedback.collect', meta({available: false, solution: {id: 'feedback', name: 'Feedback'}}))
        expect(a).toEqual({state: 'off', solutionName: 'Feedback'})
        expect(isNodeUnavailable(a)).toBe(true)
        expect(unavailableReason(a, t)).toBe('Solution Feedback is turned off')
    })

    it('flags a dotted type without metadata as not installed, by prefix before the first dot', () => {
        const a = nodeAvailability('feedback.collect.v2', undefined)
        expect(a).toEqual({state: 'missing', solutionId: 'feedback'})
        expect(unavailableReason(a, t)).toBe('Solution feedback is not installed')
    })

    it('has no reason for an available node', () => {
        expect(unavailableReason({state: 'available'}, t)).toBeNull()
    })

    it('derives a label from the type', () => {
        expect(fallbackTypeLabel('feedback.collect_text')).toBe('Feedback Collect Text')
    })
})

describe('nodeColors', () => {
    it('uses the extension scheme for dotted types and default otherwise', () => {
        expect(nodeColors('feedback.collect').color).toBe('var(--extension)')
        expect(nodeColors('unknown_core').color).toBe(nodeColors('_default').color)
        expect(nodeColors('end').color).toBe('var(--rose)')
    })
})
