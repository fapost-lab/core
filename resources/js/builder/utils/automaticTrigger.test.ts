import {describe, expect, it} from 'vitest'
import {isAutomaticTrigger} from './automaticTrigger'

describe('isAutomaticTrigger', () => {
    it('is false only for a message trigger', () => {
        expect(isAutomaticTrigger('message')).toBe(false)

        for (const type of ['schedule', 'webhook', 'api', 'event', 'acme.order_paid']) {
            expect(isAutomaticTrigger(type)).toBe(true)
        }
    })
})
