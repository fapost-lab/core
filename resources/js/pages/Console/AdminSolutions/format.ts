import { interpolate } from '@console/shell'
import type { LastChange, RangeMismatch, RegistrationPoint, SolutionsTranslations } from './types'

/** The journal line: who turned a Solution on or off, and when. */
export function changeText(t: SolutionsTranslations, change: LastChange): string {
  return interpolate(t.last_change[change.action], { actor: change.actor ?? t.last_change.unknown, date: change.atLabel })
}

/** One kit/Vue/Inertia range a Solution was built for and what this platform ships. */
export function mismatchText(t: SolutionsTranslations, mismatch: RangeMismatch): string {
  return interpolate(mismatch.unreadable ? t.kit.unreadable : t.kit.mismatch, {
    runtime: t.kit.runtime[mismatch.component],
    required: mismatch.required,
    provided: mismatch.provided,
  })
}

export function registrationLabel(t: SolutionsTranslations, point: RegistrationPoint, label: string | null, key: string): string {
  return `${t.points[point]}: ${label ?? key}`
}
