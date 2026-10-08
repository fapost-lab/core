import {describe, expect, it} from 'vitest'
import {cn} from './utils'

describe('cn', () => {
    it('joins class names and drops falsy values', () => {
        expect(cn('a', false, null, undefined, 'b', {c: true, d: false})).toBe('a b c')
    })

    it('lets the later Tailwind utility win over a conflicting earlier one', () => {
        expect(cn('px-2 py-1', 'px-4')).toBe('py-1 px-4')
    })

    it('dedupes conflicting variants of the same property', () => {
        expect(cn('text-sm text-lg', 'hover:bg-primary hover:bg-accent')).toBe('text-lg hover:bg-accent')
    })
})
