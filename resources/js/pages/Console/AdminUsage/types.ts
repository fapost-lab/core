import type { ShellPageProps } from '@console/shell'

export type UsageKind = 'records' | 'per_period' | 'bytes'
export type UsageState = 'unlimited' | 'not_reported' | 'unavailable' | 'ok' | 'near' | 'reached'

/** One limit, as `UsagePresenter` builds it: numbers already written for people, the state already decided. */
export interface UsageRowData {
  key: string
  label: string
  kind: UsageKind
  state: UsageState
  used: number | null
  limit: number | null
  usedText: string | null
  limitText: string | null
  percent: number | null
  periodEndsAt: string | null
  periodEndsLabel: string | null
}

export interface UsageAction {
  label: string
  url: string
}

export interface UsageTranslations {
  navigation: string
  title: string
  description: string
  no_limits: string
  sections: Record<UsageKind, { title: string; description: string; until?: string }>
  of: string
  only: string
  meter: string
  state: Record<'unlimited' | 'not_reported' | 'unavailable' | 'near' | 'reached', string>
  empty: string
  empty_hint: string
}

export type UsagePageProps = ShellPageProps & {
  translations: { console: ShellPageProps['translations']['console'] & { usage: UsageTranslations } }
}
