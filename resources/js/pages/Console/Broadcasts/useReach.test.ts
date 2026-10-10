import { describe, expect, it } from 'vitest'
import { firstTargetError } from './useReach'

describe('firstTargetError', () => {
  it('returns the message of a target field', () => {
    expect(firstTargetError({ target_segment_id: 'The segment is gone.' })).toBe('The segment is gone.')
  })

  it('finds an error on a list item', () => {
    expect(firstTargetError({ 'target_tags.0': 'The tag is gone.' })).toBe('The tag is gone.')
  })

  it('is null without a target error', () => {
    expect(firstTargetError({})).toBeNull()
    expect(firstTargetError({ other: 'x' })).toBeNull()
  })
})
