import type { ShellPageProps } from '@fapost/ui/shell'

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

export interface AdminDashboardTranslations {
  title: string
  description: string
  empty: string
  empty_hint: string
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
  activity: { title: string; description: string; executed: string; failed: string; day: string; empty: string }
}

export type AdminDashboardPageProps = ShellPageProps & {
  translations: { console: ShellPageProps['translations']['console'] & { admin_dashboard: AdminDashboardTranslations } }
}
