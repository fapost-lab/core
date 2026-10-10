import type { ShellPageProps } from '@fapost/ui/shell'
import type { MessageEntry } from './localized'

export interface BroadcastsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; target: string; status: string; progress: string; failed: string; skipped: string; created: string }
  statuses: Record<BroadcastStatus, string>
  targets: Record<BroadcastTarget, string>
  filters: { status: string; status_all: string }
  search_label: string
  empty: string
  empty_hint: string
  preparing: string
  actions_for: string
  actions: { edit: string; send: string; cancel: string; delete: string }
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
    segment: string
    segment_placeholder: string
    segment_missing: string
    reach: string
  }
  reach: { none: string; one: string; many: string; unavailable: string; loading: string; note: string }
  send: { title: string; description: string; name: string; audience: string; message: string; confirm: string; sending: string }
  cancel: { title: string; description: string; confirm: string; keep: string }
  delete: { title: string; description: string; confirm: string }
}

export type BroadcastsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { broadcasts: BroadcastsTranslations }
  }
}

export type BroadcastStatus = 'draft' | 'running' | 'completed' | 'failed' | 'cancelled'

export type BroadcastTarget = 'all' | 'tags' | 'segment'

export interface BroadcastRow extends Record<string, unknown> {
  id: string
  name: string
  target: BroadcastTarget
  status: BroadcastStatus
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
  createdAt: string | null
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
  message: MessageEntry[]
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
