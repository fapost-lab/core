import type { LiveChannel } from '@fapost/ui/lib/live-updates'
import type { ShellPageProps } from '@fapost/ui/shell'

export type ConversationStatus = 'open' | 'closed' | 'snoozed'
export type ConversationOwner = 'bot' | 'staff'
export type Tone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

export interface LiveTranslations {
  live: string
  polling: string
}

export interface ConversationsTranslations {
  title: string
  description: string
  search_label: string
  columns: { contact: string; platform: string; last_message: string; unread: string; messages: string; status: string; last_activity: string }
  filters: { status: string; status_all: string }
  statuses: Record<ConversationStatus, string>
  owner: { bot: string; staff: string; staff_named: string }
  content_types: Record<string, string>
  delivery: Record<string, string>
  media: { pending: string; failed: string }
  today: string
  attachment: string
  empty: string
  empty_thread: string
  load_older: string
  message_count: string
  actions: { take_over: string; return_to_bot: string; close: string; reopen: string; send: string }
  composer: { label: string; placeholder: string; hint: string; attach: string; remove: string; too_large: string; locked: string; sending: string }
  live: LiveTranslations
  back: string
  open_named: string
}

export type ConversationsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { conversations: ConversationsTranslations }
  }
  flash?: { success?: string | null; error?: string | null } | null
}

export interface ConversationRow extends Record<string, unknown> {
  id: string
  contact: string
  platform: string
  preview: string | null
  unread: number
  messages: number
  status: ConversationStatus
  owner: ConversationOwner
  lastMessageAt: string | null
  viewUrl: string
}

export interface ConversationDetails {
  id: string
  contact: string
  platform: string
  status: ConversationStatus
  owner: ConversationOwner
  ownerName: string | null
  messageCount: number
}

export interface MessageMedia {
  kind: string | null
  fileName: string | null
  status: string | null
  url: string | null
}

export interface Message {
  id: string
  direction: 'inbound' | 'outbound'
  senderType: 'contact' | 'assistant' | 'staff' | 'system'
  author: string
  contentType: string
  text: string | null
  media: MessageMedia[]
  buttons: string[]
  delivery: string | null
  createdAt: string
}

export interface Paging {
  limit: number
  hasMore: boolean
  older: number
}

export interface Abilities {
  reply: boolean
  compose: boolean
  takeOver: boolean
  returnToBot: boolean
  setStatus: boolean
}

export type { LiveChannel }

/** How a conversation status reads at a glance. */
export const STATUS_TONES: Record<ConversationStatus, Tone> = {
  open: 'success',
  snoozed: 'warning',
  closed: 'neutral',
}
