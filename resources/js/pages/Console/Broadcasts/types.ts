import type { ShellPageProps } from '@console/shell'
import type { LocalizedEntry } from '@fapost/ui/components/localized-field'

export interface BroadcastsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; target: string; status: string; progress: string; failed: string; skipped: string; created: string }
  statuses: Record<BroadcastStatus, string>
  stop_reasons: Record<BroadcastStopReason, string>
  targets: Record<BroadcastTarget, string>
  origin: { automation: string }
  filters: { status: string; status_all: string }
  search_label: string
  empty: string
  empty_hint: string
  preparing: string
  actions_for: string
  actions: { edit: string; send: string; cancel: string; delete: string; report: string }
  sections: { message: { title: string; description: string }; audience: { title: string; description: string } }
  fields: {
    name: string
    message: string
    message_help: string
    base_required: string
    target: string
    tags: string
    tags_placeholder: string
    tags_selected: string
    tags_empty: string
    tags_remove: string
    segment: string
    segment_placeholder: string
    segment_missing: string
    reach: string
  }
  reach: { none: string; one: string; many: string; unavailable: string; loading: string; note: string }
  send: { title: string; description: string; name: string; audience: string; message: string; confirm: string; sending: string }
  cancel: { title: string; description: string; confirm: string; keep: string }
  delete: { title: string; description: string; confirm: string }
  report: BroadcastReportTranslations
}

export interface BroadcastReportTranslations {
  back: string
  description: string
  not_started: string
  preparing: string
  sent_note: string
  started: string
  completed: string
  created: string
  outcomes: Record<keyof BroadcastOutcomes, string>
  recipients: {
    title: string
    columns: { contact: string; status: string; reason: string; sent: string; updated: string }
    status: string
    status_all: string
    empty: string
    empty_hint: string
    filtered: string
  }
  statuses: Record<RecipientStatus, string>
  reasons: Record<RecipientFailureReason, string>
}

export type BroadcastsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { broadcasts: BroadcastsTranslations }
  }
}

export type BroadcastStatus = 'draft' | 'running' | 'completed' | 'failed' | 'cancelled'

export type BroadcastStopReason = 'run_failed' | 'limit_reached'

export type BroadcastTarget = 'all' | 'tags' | 'segment'

export type BroadcastOrigin = 'console' | 'automation'

export interface BroadcastRow extends Record<string, unknown> {
  id: string
  name: string
  target: BroadcastTarget
  status: BroadcastStatus
  /** Why a failed or stopped run ended, when it is one of the known reasons. */
  stopReason: BroadcastStopReason | null
  sent: number
  total: number
  failed: number
  skipped: number
  /** Only a draft has one: what the person confirms when sending. */
  revision: string | null
  /** Only a draft has one: the base-language text, plain and short, shown in the send confirmation. */
  excerpt: string | null
  targetTags: string[]
  targetSegmentId: string | null
  /** Where the broadcast came from: the console form or a send_message node of a flow. */
  origin: BroadcastOrigin
  flowName: string | null
  flowUrl: string | null
  createdAt: string | null
  /** A run's report; null for a draft. */
  reportUrl: string | null
  editUrl: string | null
  sendUrl: string | null
  cancelUrl: string | null
  deleteUrl: string | null
}

export interface SelectOption {
  value: string
  label: string
}

export interface BroadcastFormState {
  name: string
  message: LocalizedEntry[]
  targetType: BroadcastTarget
  targetTags: string[]
  targetSegmentId: string | null
  segmentMissing: boolean
}

export interface BroadcastLanguage {
  code: string
  isBase: boolean
}

/** The audience as the reach endpoint takes it. */
export interface BroadcastAudience {
  targetType: BroadcastTarget
  targetTags: string[]
  targetSegmentId: string | null
}

export type RecipientStatus = 'pending' | 'sent' | 'failed' | 'skipped'

/** Why a recipient was not sent the message, as a category: the stored error text never reaches the page. */
export type RecipientFailureReason = 'run_failed' | 'limit_reached' | 'contact_missing' | 'delivery_failed' | 'stopped' | 'no_text'

/** The recipients of one broadcast by outcome, as `BroadcastReport::outcomes()` counts them. */
export interface BroadcastOutcomes {
  pending: number
  sent: number
  failed: number
  skipped: number
  total: number
}

/** The broadcast a report is about. */
export interface BroadcastSummary {
  id: string
  name: string
  target: BroadcastTarget
  status: BroadcastStatus
  stopReason: BroadcastStopReason | null
  origin: BroadcastOrigin
  flowName: string | null
  flowUrl: string | null
  createdAt: string | null
  startedAt: string | null
  completedAt: string | null
}

export interface RecipientRow extends Record<string, unknown> {
  id: string
  /** The name the platform gave; null when it gave none (the external id is shown instead). */
  contact: string | null
  externalId: string | null
  platform: string | null
  status: RecipientStatus
  reason: RecipientFailureReason | null
  sentAt: string | null
  updatedAt: string | null
  /** The contact's card, for whoever may open contacts. */
  contactUrl: string | null
}
