import type { ShellPageProps } from '@fapost/ui/shell'
import type { MessageEntry } from '../Broadcasts/localized'

export type { MessageEntry }

export type CommandType = 'terminate_session' | 'start_flow' | 'send_message'

export type SettingsTab = 'general' | 'commands' | 'advanced'

interface Section {
  title: string
  description: string
}

export interface SettingsTranslations {
  title: string
  description: string
  saved: string
  tabs: Record<SettingsTab, string>
  sections: { language: Section; flow: Section; messages: Section; commands: Section; advanced: Section }
  fields: {
    default_language: string
    default_language_locked: string
    language_placeholder: string
    available_countries: string
    available_countries_help: string
    countries_placeholder: string
    countries_selected: string
    default_flow: string
    flow_placeholder: string
    flow_inactive: string
    flow_missing: string
    fallback_message: string
    busy_message: string
    busy_message_help: string
    settings: string
    settings_key: string
    settings_value: string
    settings_add: string
    settings_remove: string
    settings_empty: string
    search: string
    nothing_found: string
    clear: string
  }
  commands: {
    add: string
    empty: string
    untitled: string
    remove: string
    expand: string
    collapse: string
    fields: { command: string; type: string; response: string; flow: string; text: string }
    types: Record<CommandType, string>
    types_help: Record<CommandType, string>
  }
  new_flow: { open: string; title: string; hint: string; name: string; submit: string }
  errors: { commands_invalid: string; unknown_language: string; command_not_start_flow: string; has_errors: string }
}

export type SettingsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { settings: SettingsTranslations }
  }
}

export interface SelectOption {
  value: string
  label: string
}

export interface FlowOption extends SelectOption {
  active: boolean
}

export interface CommandTypeOption {
  value: CommandType
  label: string
  help: string
}

/** A command as the form holds it: the command without its leading `/`, and every localized text as entries per tab. */
export interface CommandState {
  /** A key for the list only, never sent. */
  key: string
  command: string
  /** Empty when the stored action is unknown: the form then asks for one. */
  type: CommandType | ''
  response: MessageEntry[]
  flowId: string | null
  flowMissing: boolean
  text: MessageEntry[]
}

export interface SettingRow {
  key: string
  value: string
}

export interface SettingsState {
  defaultLanguage: string
  availableCountries: string[]
  defaultFlowId: string | null
  defaultFlowMissing: boolean
  fallbackMessage: MessageEntry[]
  busyMessage: MessageEntry[]
  commands: Omit<CommandState, 'key'>[]
  settings: SettingRow[]
}

export interface SettingsOptions {
  languages: SelectOption[]
  countries: SelectOption[]
  flows: FlowOption[]
  commandTypes: CommandTypeOption[]
}
