import { localizedRecord } from '@fapost/ui/components/localized-field'
import { resolveVisibility } from '@builder/composables/useFieldVisibility'
import { schemaSections } from '@builder/components/editor/config/schemaSections'
import type { CommandState, ConfigSchema, JsonValue, MessageEntry, SettingRow, SettingsTab } from './types'

/** The settings form as the page holds it; `commands` carry a list key that is never sent. */
export interface SettingsFormData {
  default_language: string
  available_countries: string[]
  default_flow_id: string | null
  fallback_message: MessageEntry[]
  busy_message: MessageEntry[]
  commands: CommandState[]
  settings: SettingRow[]
}

export interface CommandPayload {
  command: string
  type: string
  response?: Record<string, string>
  flow_id?: string | null
  text?: Record<string, string>
  config?: Record<string, JsonValue>
}

export interface SettingsPayload {
  default_language: string
  available_countries: string[]
  default_flow_id: string | null
  fallback_message: Record<string, string>
  busy_message: Record<string, string>
  commands: CommandPayload[]
  settings: SettingRow[]
}

const TABS: SettingsTab[] = ['general', 'commands', 'advanced']

export function isTab(value: unknown): value is SettingsTab {
  return typeof value === 'string' && (TABS as string[]).includes(value)
}

/** One empty entry per language, in the order of the tabs. */
export function emptyEntries(languages: string[]): MessageEntry[] {
  return languages.map((locale) => ({ locale, text: '' }))
}

/** A new command row: it ends the session, as the Filament form offered first. */
export function newCommand(languages: string[], key: string): CommandState {
  return { key, command: '', type: 'terminate_session', response: emptyEntries(languages), flowId: null, flowMissing: false, text: emptyEntries(languages) }
}

/**
 * What is sent: each command (a Solution's action as stored) with only the fields its type uses, and each localized text as the languages that have
 * text. The server normalizes the same way; sending less keeps a hidden field from failing validation.
 */
export function toPayload(data: SettingsFormData): SettingsPayload {
  return {
    default_language: data.default_language,
    available_countries: data.available_countries,
    default_flow_id: data.default_flow_id,
    fallback_message: localizedRecord(data.fallback_message),
    busy_message: localizedRecord(data.busy_message),
    commands: data.commands.map((command) => {
      // A type no Solution registers has no form: it goes back exactly as stored, so saving other settings never loses it.
      if (command.extension) {
        return { command: command.command.trim(), type: command.extension.type, config: command.config ?? {} }
      }

      const row: CommandPayload = { command: command.command.trim(), type: command.type }

      if (command.type === 'terminate_session') {
        row.response = localizedRecord(command.response)
      } else if (command.type === 'start_flow') {
        row.flow_id = command.flowId
      } else if (command.type === 'send_message') {
        row.text = localizedRecord(command.text)
      } else if (command.config) {
        // A Solution's action: its whole config, keys its form does not show included.
        row.config = command.config
      }

      return row
    }),
    // A row with neither key nor value was added and left: it is dropped, not an error.
    settings: data.settings.filter((row) => row.key.trim() !== '' || row.value.trim() !== ''),
  }
}

/** One field of a Solution action's config form. */
export interface ConfigField {
  key: string
  schema: Record<string, unknown>
  required: boolean
}

/** The field types the console's config form edits; any other is shown read-only and sent back as it is. */
const EDITABLE_FIELD_TYPES = new Set(['string', 'text', 'number', 'boolean', 'enum', 'enum-cards'])

export function isEditableField(field: ConfigField): boolean {
  return EDITABLE_FIELD_TYPES.has(String(field.schema.type ?? 'string'))
}

/**
 * The fields of a config form in the order they are shown: the declared sections' fields, section after section, or
 * every field when the schema declares none — read as the builder reads a node's `config_schema`.
 */
export function configFields(schema: ConfigSchema | undefined): ConfigField[] {
  return schemaSections(schema, { key: 'config', label: '' }).flatMap((section) => section.fields)
}

/** The fields a config shows now: a field's `visible_when` is checked against the config being edited. */
export function visibleConfigFields(schema: ConfigSchema | undefined, config: Record<string, JsonValue>): ConfigField[] {
  return configFields(schema).filter((field) => resolveVisibility(field.schema, config))
}

/** The config a command starts with when its Solution action is chosen: each field's default, then the schema's `default_config`. */
export function defaultConfig(schema: ConfigSchema | undefined): Record<string, JsonValue> {
  const config: Record<string, JsonValue> = {}

  for (const field of configFields(schema)) {
    if (field.schema.default !== undefined) {
      config[field.key] = field.schema.default as JsonValue
    }
  }

  const declared = schema?.default_config

  return declared !== null && typeof declared === 'object' && !Array.isArray(declared) ? { ...config, ...(declared as Record<string, JsonValue>) } : config
}

/**
 * An `enum` field's choices: its `options` (or the older `values`) as a list of values, a list of `{value, label}`
 * cards (`enum-cards`), or a map of value to label.
 */
export function enumChoices(field: ConfigField): { value: string; label: string }[] {
  const raw = field.schema.options ?? field.schema.values ?? []

  if (Array.isArray(raw)) {
    return raw.map((entry) => {
      if (entry !== null && typeof entry === 'object' && 'value' in entry) {
        const card = entry as { value: unknown; label?: unknown }

        return { value: String(card.value), label: String(card.label ?? card.value) }
      }

      return { value: String(entry), label: String(entry) }
    })
  }

  if (raw !== null && typeof raw === 'object') {
    return Object.entries(raw as Record<string, unknown>).map(([value, label]) => ({ value, label: String(label) }))
  }

  return []
}

/**
 * The form's commands with the warnings of the commands as saved: each takes those of the saved command with its name
 * and type, and none when there is no such command.
 */
export function refreshWarnings(commands: CommandState[], saved: Omit<CommandState, 'key'>[]): CommandState[] {
  const name = (command: string): string => command.trim().replace(/^\/+/, '')

  return commands.map((command) => ({
    ...command,
    warnings: saved.find((row) => name(row.command) === name(command.command) && row.type === command.type)?.warnings ?? [],
  }))
}

/** The tab a validation error belongs to, by the key the server reports it under. */
export function tabOfError(key: string): SettingsTab {
  if (key === 'commands' || key.startsWith('commands.') || key === 'command_index') {
    return 'commands'
  }

  if (key.startsWith('settings')) {
    return 'advanced'
  }

  return 'general'
}

/** The tabs that hold an error, in the order of the tabs, so the page can mark them and open the first. */
export function tabsWithErrors(errors: Record<string, string | undefined>): SettingsTab[] {
  const tabs = new Set(Object.keys(errors).filter((key) => errors[key] !== undefined).map(tabOfError))

  return TABS.filter((tab) => tabs.has(tab))
}

/** The errors of one command, under keys relative to it (`command`, `flow_id`, `response.en`). */
export function commandErrors(errors: Record<string, string | undefined>, index: number): Record<string, string | undefined> {
  const prefix = `commands.${index}.`
  const own: Record<string, string | undefined> = {}

  for (const [key, message] of Object.entries(errors)) {
    if (key.startsWith(prefix)) {
      own[key.slice(prefix.length)] = message
    }
  }

  return own
}

/** The first error among a field and its items (`available_countries`, `available_countries.0`). */
export function firstError(errors: Record<string, string | undefined>, field: string): string | undefined {
  return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
}
