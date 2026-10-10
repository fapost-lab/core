import type { ShellPageProps } from '@fapost/ui/shell'
import type { ActivityPeriod, KeyValue, LiveTranslations, Tone } from '../FlowSessions/types'

export type FlowLogStatus = 'executed' | 'failed' | 'conflict' | 'terminal'

export interface FlowLogsTranslations {
  title: string
  description: string
  search_label: string
  columns: { created_at: string; status: string; node_type: string; node_id: string; source_handle: string; error: string; session: string }
  filters: { period: string; session: string; session_clear: string; type: string; type_all: string; status: string; status_all: string; errors: string }
  statuses: Record<FlowLogStatus, string>
  periods: Record<ActivityPeriod, string>
  live: LiveTranslations
  has_error: string
  open_named: string
  empty: string
  empty_hint: string
  back: string
  view: {
    entry: string
    created_at: string
    status: string
    session: string
    node_id: string
    node_type: string
    node_version: string
    source_handle: string
    state_changes: string
    resolved: string
    error: string
    open_session: string
    empty_value: string
  }
}

export type FlowLogsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { flow_logs: FlowLogsTranslations }
  }
}

export interface FlowLogRow extends Record<string, unknown> {
  id: string
  createdAt: string
  status: FlowLogStatus
  nodeType: string
  nodeId: string
  sourceHandle: string | null
  hasError: boolean
  sessionId: string
  sessionShortId: string
  viewUrl: string
  sessionUrl: string | null
}

export interface FlowLogDetails {
  id: string
  createdAt: string
  status: FlowLogStatus
  sessionId: string
  nodeId: string
  nodeType: string
  nodeVersion: number
  sourceHandle: string | null
}

export type { KeyValue }

export const LOG_STATUS_TONES: Record<FlowLogStatus, Tone> = {
  executed: 'success',
  terminal: 'success',
  failed: 'danger',
  conflict: 'danger',
}
