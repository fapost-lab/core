import type { ShellPageProps } from '@console/shell'
import type { RunGraphTranslations } from '@console/run-graph/runGraph'
import type { TemplateNodeOrigin } from './templateNode'

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
  template_node: string
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
    graph: string
    empty_value: string
  }
  rerun: RerunTranslations
  graph: RunGraphTranslations
}

export type RerunRefusal =
  | 'not_an_automation'
  | 'not_a_root'
  | 'not_failed'
  | 'no_payload'
  | 'trigger_gone'
  | 'flow_off'
  | 'trigger_paused'
  | 'no_active_version'
  | 'already_rerun'

export interface RerunTranslations {
  action: string
  confirm_title: string
  confirm_description: string
  version_changed: string
  confirm: string
  queued: string
  refusals: Record<RerunRefusal, string>
  reruns: string
  reruns_description: string
  rerun_of: string
}

/** What the page offers to re-run the session from the start; null for a user who may not. */
export interface RerunOffer {
  allowed: boolean
  refusal: RerunRefusal | null
  targetVersion: number | null
  /** The re-run a request starts; sent back, so a repeated request starts no second run. */
  attempt: number | null
  url: string
}

export interface RerunRow {
  id: string
  status: FlowSessionStatus
  createdAt: string | null
  url: string
}

export interface RerunSource {
  id: string
  url: string
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
  currentNodeOrigin: TemplateNodeOrigin | null
  updatedAt: string | null
  viewUrl: string
}

export interface FlowSessionDetails {
  id: string
  status: FlowSessionStatus
  endStatus: string | null
  currentNodeId: string | null
  currentNodeOrigin: TemplateNodeOrigin | null
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
  nodeOrigin: TemplateNodeOrigin | null
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

/** A run in one of these may still move soon: a page listing it keeps itself current (`paused` sleeps in a delay). */
export const RUNNING_STATUSES: readonly FlowSessionStatus[] = ['pending', 'active', 'waiting_input', 'paused_subflow']
