import type { ShellPageProps } from '@console/shell'

export interface ChannelsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { type: string; bot: string; active: string; updated: string }
  bot_pending: string
  unavailable: string
  webhook: { failed: string; failed_hint: string; failed_at: string; register_again: string }
  status: { active: string; inactive: string }
  sections: { connection: { title: string; description: string }; behaviour: { title: string; description: string } }
  setup: { title: string; description: string }
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
    select_all: string
    clear_all: string
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
  /** False when no installed integration serves the type: the channel can only be deleted. */
  available: boolean
  handle: string | null
  url: string | null
  isActive: boolean
  webhook: 'failed' | null
  updatedAt: string | null
  editUrl: string
  registerWebhookUrl: string
  deleteUrl: string
  rotateUrl: string
}

export interface SelectOption {
  value: string
  label: string
}

/** A channel type the form offers. */
export interface ChannelTypeOption extends SelectOption {
  /** False when the provider has no webhook secret: the form then neither shows nor requires one. */
  usesSecretToken: boolean
}

/** What a setting holds; the server builds the rules from it. */
export type SettingType = 'text' | 'integer' | 'boolean' | 'choice' | 'choices'

export type SettingValue = string | number | boolean | string[] | null

/** One setting an integration declared for its channel type, labelled in the interface language. */
export interface SettingField {
  key: string
  type: SettingType
  label: string
  help: string | null
  required: boolean
  default: SettingValue
  min: number | null
  max: number | null
  choices: SelectOption[]
}

/** The settings of every channel type, by type. */
export type SettingsByType = Record<string, SettingField[]>

/** What a step's value is, so the form renders it as a line, a link or a block. */
export type SetupCopyKind = 'text' | 'url' | 'code'

/** One step of a provider's setup guide, texts already in the interface language. */
export interface SetupStep {
  title: string
  text: string | null
  /** A value to copy: the webhook URL, a manifest. */
  copy: string | null
  copyKind: SetupCopyKind
}

/** The setup guide of every channel type, by type, before the channel exists (its URL is not known yet). */
export type SetupByType = Record<string, SetupStep[]>

/** A channel as the edit page receives it. The token and the secret token are never sent. */
export interface EditableChannel {
  id: string
  type: string
  typeLabel: string
  available: boolean
  isActive: boolean
  webhook: 'failed' | null
  webhookAt: string | null
  registerWebhookUrl: string
  webhookHash: string
  handle: string | null
  url: string | null
  /** The stored value of each editable setting, by key. */
  settings: Record<string, SettingValue>
  /** The setup guide with this channel's webhook URL; empty when the integration has none. */
  setup: SetupStep[]
}
