import {describe, expect, it} from 'vitest'
import {groupPaletteTypes} from './paletteGroups'

const type = (name: string, over: Record<string, unknown> = {}) => ({
    type: name, version: 1, label: name, category: 'Logic', config_schema: {}, ...over,
}) as never

describe('groupPaletteTypes', () => {
    const order = [
        {key: 'Conversation', label: 'Conversation', owner: null},
        {key: 'Logic', label: 'Logic', owner: null},
        {key: 'feedback', label: 'Feedback', owner: 'feedback'},
    ]

    it('groups by group key, ordered by the server list, labelled by group label', () => {
        const result = groupPaletteTypes([
            type('feedback.collect', {category: 'feedback', group: order[2]}),
            type('condition', {group: order[1]}),
            type('send_message', {category: 'Conversation', group: order[0]}),
            type('delay', {group: order[1]}),
        ], order)

        expect(result.map((g) => g.key)).toEqual(['Conversation', 'Logic', 'feedback'])
        expect(result[2]!.label).toBe('Feedback')
        expect(result[1]!.types.map((t) => t.type)).toEqual(['condition', 'delay'])
    })

    it('falls back to category in first-appearance order when the response has no groups', () => {
        const result = groupPaletteTypes([
            type('a', {category: 'Data'}), type('b', {category: 'Logic'}), type('c', {category: 'Data'}),
        ], [])

        expect(result.map((g) => g.key)).toEqual(['Data', 'Logic'])
        expect(result[0]!.types).toHaveLength(2)
    })

    it('puts groups missing from the order list after the listed ones', () => {
        const result = groupPaletteTypes([type('x', {category: 'Extra'}), type('y', {group: order[1]})], order)
        expect(result.map((g) => g.key)).toEqual(['Logic', 'Extra'])
    })
})
