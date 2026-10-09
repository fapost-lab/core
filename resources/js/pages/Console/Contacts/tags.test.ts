import { describe, expect, it } from 'vitest'
import { addTags, removeTag } from './tags'

describe('addTags', () => {
  it('adds a tag after trimming it', () => {
    expect(addTags(['a'], '  b ')).toEqual(['a', 'b'])
  })

  it('splits on commas', () => {
    expect(addTags([], 'a, b,c')).toEqual(['a', 'b', 'c'])
  })

  it('ignores blanks', () => {
    expect(addTags(['a'], ' , ,')).toEqual(['a'])
    expect(addTags(['a'], '')).toEqual(['a'])
  })

  it('ignores a tag already there, also within the same input', () => {
    expect(addTags(['a'], 'a, b, b')).toEqual(['a', 'b'])
  })

  it('treats a different case as a different tag', () => {
    expect(addTags(['vip'], 'VIP')).toEqual(['vip', 'VIP'])
  })

  it('does not change the list it was given', () => {
    const current = ['a']

    addTags(current, 'b')

    expect(current).toEqual(['a'])
  })
})

describe('removeTag', () => {
  it('removes the tag', () => {
    expect(removeTag(['a', 'b'], 'a')).toEqual(['b'])
  })

  it('leaves the list as it is when the tag is absent', () => {
    expect(removeTag(['a'], 'x')).toEqual(['a'])
  })
})
