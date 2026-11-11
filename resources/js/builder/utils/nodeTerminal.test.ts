import {describe, expect, it} from 'vitest'

import {nodeMustBeLast} from './nodeTerminal'

describe('nodeMustBeLast', () => {
    it('treats request_operator as always terminal', () => {
        expect(nodeMustBeLast({type: 'request_operator'})).toBe(true)
        expect(nodeMustBeLast({type: 'request_operator', config: {wait_seconds: 0}})).toBe(true)
    })

    it('keeps a plain notify node in the chain', () => {
        expect(nodeMustBeLast({type: 'notify'})).toBe(false)
    })
})
