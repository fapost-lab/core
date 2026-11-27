import type { ShellPageProps } from '@console/shell'
import type { LocalizedEntry } from '@fapost/ui/components/localized-field'

/** One language of a localized setting. */
export type MessageEntry = LocalizedEntry

/** Core's own command actions; a Solution's action is a dotted type such as `feedback.thank`. */
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
    extension_help: string
    extension_not_installed: string
    solution_inactive: string
    config_unsupported: string
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
  /** A {@link CommandType}, or the dotted type of a Solution's action. */
  value: string
  label: string
  help: string
  /** A Solution's action only: its config form, in the schema language of a flow node's `config_schema`. */
  schema?: ConfigSchema
  /** A Solution's action only: whether its Solution is on for the tenant; one that is off is offered only where it is chosen. */
  available?: boolean
}

/** A config form as a Solution's action declares it: fields by key, plus the reserved `required`, `sections` and `default_config`. */
export type ConfigSchema = Record<string, unknown>

/** What a Solution's action warns about in a stored config; it does not refuse the save. */
export interface CommandConfigWarning {
  field: string | null
  message: string
}

/** A command as the form holds it: the command without its leading `/`, and every localized text as entries per tab. */
export interface CommandState {
  /** A key for the list only, never sent. */
  key: string
  command: string
  /** A {@link CommandType} or a Solution's dotted type; empty when the stored action is unknown: the form then asks for one. */
  type: CommandType | (string & {}) | ''
  response: MessageEntry[]
  flowId: string | null
  flowMissing: boolean
  text: MessageEntry[]
  /** The config of a Solution's action, edited through its type's form; sent back as stored when no Solution registers the type. */
  config?: Record<string, JsonValue> | null
  /** What the type warns about in the stored config. */
  warnings?: CommandConfigWarning[]
  /** Set for a dotted type no Solution registers: the row is read-only and the type lives here; `type` is then not used. */
  extension?: CommandExtension | null
}

/** What a Solution's action config holds: plain JSON, which the form passes through untouched (kept loose: a recursive type is too deep for the form helper). */
export type JsonValue = string | number | boolean | null | object

/** A stored command's dotted type that no installed Solution registers, as the server describes it. */
export interface CommandExtension {
  /** The dotted type, e.g. `feedback.thank`. */
  type: string
  label: string
  installed: boolean
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
