import { describe, expect, it } from 'vitest'
import { addValues, removeValue } from './values'

describe('addValues', () => {
  it('adds a value after trimming it', () => {
    expect(addValues(['a'], '  b ')).toEqual(['a', 'b'])
  })

  it('splits on commas', () => {
    expect(addValues([], 'a, b,c')).toEqual(['a', 'b', 'c'])
  })

  it('ignores blanks', () => {
    expect(addValues(['a'], ' , ,')).toEqual(['a'])
    expect(addValues(['a'], '')).toEqual(['a'])
  })

  it('ignores a value already there, also within the same input', () => {
    expect(addValues(['a'], 'a, b, b')).toEqual(['a', 'b'])
  })

  it('treats a different case as a different value', () => {
    expect(addValues(['vip'], 'VIP')).toEqual(['vip', 'VIP'])
  })

  it('does not change the list it was given', () => {
    const current = ['a']

    addValues(current, 'b')

    expect(current).toEqual(['a'])
  })
})

describe('removeValue', () => {
  it('removes the value', () => {
    expect(removeValue(['a', 'b'], 'a')).toEqual(['b'])
  })

  it('leaves the list as it is when the value is absent', () => {
    expect(removeValue(['a'], 'x')).toEqual(['a'])
  })
})
