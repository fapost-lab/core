import { cleanMessage, type MessageEntry } from '../Broadcasts/localized'
import type { CommandState, SettingRow, SettingsTab } from './types'

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
  type: CommandState['type']
  response?: Record<string, string>
  flow_id?: string | null
  text?: Record<string, string>
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
 * What is sent: each command with only the fields its type uses, and each localized text as the languages that have
 * text. The server normalizes the same way; sending less keeps a hidden field from failing validation.
 */
export function toPayload(data: SettingsFormData): SettingsPayload {
  return {
    default_language: data.default_language,
    available_countries: data.available_countries,
    default_flow_id: data.default_flow_id,
    fallback_message: cleanMessage(data.fallback_message),
    busy_message: cleanMessage(data.busy_message),
    commands: data.commands.map((command) => {
      const row: CommandPayload = { command: command.command.trim(), type: command.type }

      if (command.type === 'terminate_session') {
        row.response = cleanMessage(command.response)
      } else if (command.type === 'start_flow') {
        row.flow_id = command.flowId
      } else {
        row.text = cleanMessage(command.text)
      }

      return row
    }),
    // A row with neither key nor value was added and left: it is dropped, not an error.
    settings: data.settings.filter((row) => row.key.trim() !== '' || row.value.trim() !== ''),
  }
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

/** The errors under one prefix (`fallback_message.ru` → `ru`), for a localized field. */
export function errorsUnder(errors: Record<string, string | undefined>, prefix: string): Record<string, string | undefined> {
  const own: Record<string, string | undefined> = {}

  for (const [key, message] of Object.entries(errors)) {
    if (key.startsWith(`${prefix}.`)) {
      own[key.slice(prefix.length + 1)] = message
    }
  }

  return own
}

/** The first error among a field and its items (`available_countries`, `available_countries.0`). */
export function firstError(errors: Record<string, string | undefined>, field: string): string | undefined {
  return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
}
