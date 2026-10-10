import type { ShellPageProps } from '@fapost/ui/shell'

export type FlowSessionStatus =
  | 'pending'
  | 'active'
  | 'waiting_input'
  | 'paused'
  | 'paused_subflow'
  | 'completed'
  | 'ended'
  | 'failed'
  | 'cancelled'
  | 'expired'
  | 'terminated_by_user'

export type ActivityPeriod = '1h' | '24h' | '7d' | '30d'

export interface LiveTranslations {
  live: string
  polling: string
}

export interface FlowSessionsTranslations {
  title: string
  description: string
  search_label: string
  columns: { id: string; status: string; end_status: string; contact: string; flow: string; current_node: string; updated_at: string }
  filters: { status: string; status_live: string; status_all: string; flow: string; flow_all: string; period: string; period_any: string }
  statuses: Record<FlowSessionStatus, string>
  end_statuses: Record<string, string>
  periods: Record<ActivityPeriod, string>
  live: LiveTranslations
  open_named: string
  empty: string
  empty_live: string
  empty_hint: string
  back: string
  view: {
    identity: string
    status: string
    end_status: string
    current_node: string
    version: string
    flow: string
    flow_version: string
    contact: string
    created_at: string
    updated_at: string
    parent: string
    parent_session: string
    parent_resume_node: string
    expires_at: string
    state: string
    state_empty: string
    history: string
    history_note: string
    history_empty: string
    logs: string
    empty_value: string
  }
}

export type FlowSessionsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { flow_sessions: FlowSessionsTranslations }
  }
}

export interface FlowSessionRow extends Record<string, unknown> {
  id: string
  shortId: string
  status: FlowSessionStatus
  endStatus: string | null
  contactExternalId: string | null
  flowName: string | null
  currentNodeId: string | null
  updatedAt: string | null
  viewUrl: string
}

export interface FlowSessionDetails {
  id: string
  status: FlowSessionStatus
  endStatus: string | null
  currentNodeId: string | null
  version: number
  flowName: string | null
  flowVersion: number
  contactExternalId: string | null
  createdAt: string | null
  updatedAt: string | null
  parentId: string | null
  parentResumeNodeId: string | null
  expiresAt: string | null
  isLive: boolean
}

export interface KeyValue {
  key: string
  value: string
}

export interface HistoryEntry {
  id: string
  createdAt: string | null
  nodeId: string
  event: string
  path: string | null
  payload: string | null
}

export type Tone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

/** How a session status reads at a glance; the same grouping the Filament list used. */
export const STATUS_TONES: Record<FlowSessionStatus, Tone> = {
  pending: 'neutral',
  active: 'info',
  waiting_input: 'warning',
  paused: 'neutral',
  paused_subflow: 'neutral',
  completed: 'success',
  ended: 'success',
  failed: 'danger',
  cancelled: 'neutral',
  expired: 'danger',
  terminated_by_user: 'neutral',
}

export const END_STATUS_TONES: Record<string, 'success' | 'danger' | 'neutral'> = {
  success: 'success',
  failed: 'danger',
  cancelled: 'neutral',
}
