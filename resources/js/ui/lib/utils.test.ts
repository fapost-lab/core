import {describe, expect, it} from 'vitest'
import {cn, interpolate} from './utils'

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

describe('interpolate', () => {
    it('fills the placeholders', () => {
        expect(interpolate('Live sessions (:count)', {count: 3})).toBe('Live sessions (3)')
        expect(interpolate('(:name, :email)', {name: 'Olga', email: 'o@x.io'})).toBe('(Olga, o@x.io)')
    })

    it('fills a long name before a short one that starts it', () => {
        expect(interpolate('Showing :from–:to of :total', {from: 1, to: 4, total: 4})).toBe('Showing 1–4 of 4')
        expect(interpolate('Showing :from–:to of :total', {to: 4, from: 1, total: 12})).toBe('Showing 1–4 of 12')
    })

    it('inserts a value verbatim', () => {
        expect(interpolate('Delete :name?', {name: "a$&b$'c"})).toBe("Delete a$&b$'c?")
    })
})
