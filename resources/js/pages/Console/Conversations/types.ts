import type { LiveChannel } from '@console/lib/live-updates'
import type { ShellPageProps } from '@console/shell'

export type ConversationOwner = 'bot' | 'queued' | 'staff'
export type Handling = 'queued' | 'mine' | 'staff' | 'bot'

export interface LiveTranslations {
  live: string
  polling: string
}

export interface ConversationsTranslations {
  title: string
  description: string
  search_label: string
  columns: { contact: string; platform: string; last_message: string; unread: string; messages: string; status: string; last_activity: string }
  filters: { handling: string; handling_all: string }
  handlings: Record<Handling, string>
  owner: { bot: string; queued: string; staff: string; staff_named: string; waiting: string; you: string }
  handling_status: { bot: string; queued_since: string; staff_named: string; staff_you: string; staff: string }
  content_types: Record<string, string>
  delivery: Record<string, string>
  media: { pending: string; failed: string }
  today: string
  attachment: string
  empty: string
  empty_thread: string
  load_older: string
  message_count: string
  actions: { take_over: string; accept: string; finish: string; transfer: string; seize: string; send: string; cancel: string }
  transfer: { title: string; description: string; label: string; placeholder: string; confirm: string; none: string }
  seize: { title: string; description: string; confirm: string }
  finish: { title: string; description: string; confirm: string }
  composer: { label: string; placeholder: string; hint: string; attach: string; remove: string; too_large: string; locked: string; locked_queued: string; locked_staff: string; sending: string }
  live: LiveTranslations
  back: string
  open_named: string
  threads: { title: string; search: string; empty: string; no_match: string }
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
  owner: ConversationOwner
  ownerName: string | null
  queuedAt: string | null
  mine: boolean
  lastMessageAt: string | null
  viewUrl: string
}

export interface ConversationDetails {
  id: string
  contact: string
  platform: string
  owner: ConversationOwner
  ownerId: string | null
  ownerName: string | null
  queuedAt: string | null
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
  finish: boolean
  transfer: boolean
  seize: boolean
}

export interface TransferTarget {
  id: string
  name: string
}

export interface ConversationUrls {
  index: string
  self: string
  reply: string
  takeOver: string
  finish: string
  transfer: string
  seize: string
}

export type { LiveChannel }

