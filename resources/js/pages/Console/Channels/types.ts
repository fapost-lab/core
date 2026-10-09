import type { ShellPageProps } from '@fapost/ui/shell'

export interface ChannelsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { type: string; bot: string; active: string; updated: string }
  bot_pending: string
  status: { active: string; inactive: string }
  fields: {
    type: string
    type_locked: string
    token: string
    token_keep: string
    secret_token: string
    secret_token_help: string
    secret_token_keep: string
    generate: string
    show: string
    hide: string
    is_active: string
    webhook_hash: string
    webhook_hash_help: string
    copy: string
    copied: string
    config: string
    allowed_updates: string
    allowed_updates_help: string
    select_all: string
    clear_all: string
    max_connections: string
    max_connections_help: string
    config_key: string
    config_value: string
    config_add: string
    config_remove: string
  }
  empty: string
  empty_hint: string
  actions_for: string
  rotate: string
  rotate_dialog: { title: string; description: string }
  delete_one: { title: string; description: string }
}

export type ChannelsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { channels: ChannelsTranslations }
  }
}

export interface ChannelRow extends Record<string, unknown> {
  id: string
  type: string
  typeLabel: string
  handle: string | null
  url: string | null
  isActive: boolean
  updatedAt: string | null
  editUrl: string
  deleteUrl: string
  rotateUrl: string
}

export interface SelectOption {
  value: string
  label: string
}

export interface MaxConnections {
  min: number
  max: number
  default: number
}

export interface ConfigEntry {
  key: string
  value: string
}

/** A channel as the edit page receives it. The token and the secret token are never sent. */
export interface EditableChannel {
  id: string
  type: string
  typeLabel: string
  isActive: boolean
  webhookHash: string
  handle: string | null
  url: string | null
  telegram: { allowedUpdates: string[]; maxConnections: number } | null
  configEntries: ConfigEntry[] | null
}
