import { describe, expect, it } from 'vitest'
import { isCountable } from './audience'

describe('isCountable', () => {
  it('counts everyone without asking for more', () => {
    expect(isCountable({ targetType: 'all', targetTags: [], targetSegmentId: null })).toBe(true)
  })

  it('needs a tag for a tag audience', () => {
    expect(isCountable({ targetType: 'tags', targetTags: [], targetSegmentId: null })).toBe(false)
    expect(isCountable({ targetType: 'tags', targetTags: ['vip'], targetSegmentId: null })).toBe(true)
  })

  it('needs a segment for a segment audience', () => {
    expect(isCountable({ targetType: 'segment', targetTags: [], targetSegmentId: null })).toBe(false)
    expect(isCountable({ targetType: 'segment', targetTags: [], targetSegmentId: '' })).toBe(false)
    expect(isCountable({ targetType: 'segment', targetTags: [], targetSegmentId: 'abc' })).toBe(true)
  })

  it('ignores the fields the other targets use', () => {
    expect(isCountable({ targetType: 'all', targetTags: ['x'], targetSegmentId: 'abc' })).toBe(true)
  })
})
