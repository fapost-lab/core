import type { ShellPageProps } from '@console/shell'

export type RegistrationPoint = 'context_action' | 'shell_element' | 'dashboard_widget' | 'settings_tab' | 'table_column'

/** One console declaration of a Solution, as `SolutionsPresenter` builds it. */
export interface Registration {
  point: RegistrationPoint
  target: string
  key: string
  label: string | null
}

export interface RangeMismatch {
  component: 'kit' | 'vue' | 'inertia'
  required: string
  provided: string
  unreadable: boolean
}

export interface SolutionUi {
  state: 'published' | 'not_published'
  application: string | null
  registrations: Registration[]
  pages: string[]
  mismatches: RangeMismatch[]
}

export interface LastChange {
  action: 'activated' | 'deactivated'
  actor: string | null
  at: string
  atLabel: string
}

/** Whether the operator entitles the workspace to a Solution; `paused`: turned on, but not running because it is not entitled. */
export interface Entitlement {
  state: 'entitled' | 'not_entitled' | 'unavailable'
  paused: boolean
  action: { label: string; url: string } | null
}

export interface SolutionRow {
  id: string
  name: string
  description: string | null
  package: string
  version: string | null
  active: boolean
  actions: string[]
  ui: SolutionUi | null
  lastChange: LastChange | null
  canActivate: boolean
  entitlement: Entitlement
  urls: { activate: string; deactivate: string }
}

/** A Solution whose activation is still stored but whose package is no longer installed. */
export interface MissingRow {
  id: string
  lastChange: LastChange | null
  urls: { deactivate: string }
}

export interface SolutionsTranslations {
  navigation: string
  title: string
  description: string
  empty: string
  empty_hint: string
  version: string
  package: string
  state: Record<'active' | 'inactive', string>
  activate: string
  deactivate: string
  details: string
  last_change: Record<'activated' | 'deactivated' | 'unknown', string>
  registers: { title: string; actions: string; none: string }
  ui: { title: string; application: string; pages: string; not_published: string; inactive_note: string }
  points: Record<RegistrationPoint, string>
  kit: { badge: string; title: string; description: string; mismatch: string; unreadable: string; runtime: Record<RangeMismatch['component'], string> }
  entitlement: { badge: string; paused: string; not_entitled: string; unavailable: string; paused_note: string; off_note: string; unavailable_note: string }
  deactivate_dialog: { title: string; description: string; confirm: string }
  missing: { title: string; description: string }
}

export type SolutionsPageProps = ShellPageProps & {
  translations: { console: ShellPageProps['translations']['console'] & { solutions: SolutionsTranslations } }
}
