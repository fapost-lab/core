import type { ShellPageProps } from '@console/shell'
import type { UsageAction, UsageRowData, UsageTranslations } from '../AdminUsage/types'

/** One UTC day of the flow activity chart, as `TenantFlowActivity::daily()` builds it. */
export interface ActivityDay {
  date: string
  executed: number
  failed: number
}

/** The figures the user may see; a figure they may not is `null`. */
export interface AdminStats {
  assistants: { total: number; active: number } | null
  contacts: { total: number } | null
  channels: { total: number; active: number } | null
  flows: { published: number } | null
  sessions: { waiting: number } | null
  staff: { total: number } | null
}

/** The dashboard's "Limits and usage" card, as `UsagePresenter::widget()` builds it. */
export interface UsageWidgetData {
  rows: UsageRowData[]
  total: number
  /** How many limits could not be read right now. */
  unavailable: number
  action: UsageAction | null
  limited: boolean
  url: string
}

export interface AdminDashboardTranslations {
  title: string
  description: string
  empty: string
  empty_hint: string
  stale: string
  refresh: string
  stats: Record<
    | 'assistants'
    | 'assistants_hint'
    | 'contacts'
    | 'contacts_hint'
    | 'channels'
    | 'channels_hint'
    | 'flows'
    | 'flows_hint'
    | 'sessions'
    | 'sessions_hint'
    | 'staff'
    | 'staff_hint',
    string
  >
  usage: { title: string; description: string; all: string; none: string; more: string; unavailable: string }
  activity: { title: string; description: string; executed: string; failed: string; day: string; empty: string }
}

export type AdminDashboardPageProps = ShellPageProps & {
  translations: { console: ShellPageProps['translations']['console'] & { admin_dashboard: AdminDashboardTranslations; usage: UsageTranslations } }
}
