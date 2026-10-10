import type { BroadcastAudience } from './types'

/**
 * Whether the audience is complete enough to count: tags need a tag, a segment needs a segment. Asking earlier would
 * only draw a validation error for a field the person has not filled in yet.
 */
export function isCountable(audience: BroadcastAudience): boolean {
  if (audience.targetType === 'tags') {
    return audience.targetTags.length > 0
  }

  if (audience.targetType === 'segment') {
    return audience.targetSegmentId !== null && audience.targetSegmentId !== ''
  }

  return true
}
