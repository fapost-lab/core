import {describe, expect, it} from 'vitest'
import {compileVariable, decodeStoredVariable, isValidListSize} from './variableCompiler'

describe('list max_size', () => {
    const list = {name: 'photos', type: 'photo', storage: 'contact', group: null, isList: true} as const

    it('compiles a valid max_size for a contact list', () => {
        expect(compileVariable({...list, maxSize: 250}).properties).toEqual({item_type: 'photo', max_size: 250})
    })

    it('omits an empty, invalid or session max_size', () => {
        expect(compileVariable({...list, maxSize: null}).properties).toEqual({item_type: 'photo'})
        expect(compileVariable({...list, maxSize: 0}).properties).toEqual({item_type: 'photo'})
        expect(compileVariable({...list, maxSize: 1001}).properties).toEqual({item_type: 'photo'})
        expect(compileVariable({...list, maxSize: 2.5}).properties).toEqual({item_type: 'photo'})
        expect(compileVariable({...list, storage: 'session', maxSize: 10}).properties).toEqual({item_type: 'photo'})
    })

    it('does not add properties to a plain variable', () => {
        expect(compileVariable({name: 'x', type: 'text', storage: 'contact', group: null}).properties).toBeUndefined()
    })

    it('decodes max_size back', () => {
        const stored = {name: 'photos', type: 'array', storage: 'contact', properties: {item_type: 'photo', max_size: 30}}
        expect(decodeStoredVariable(stored).maxSize).toBe(30)
        expect(decodeStoredVariable({...stored, properties: {item_type: 'photo'}}).maxSize).toBeNull()
    })

    it('validates the bounds', () => {
        expect(isValidListSize(1)).toBe(true)
        expect(isValidListSize(1000)).toBe(true)
        expect(isValidListSize('5')).toBe(false)
    })
})
