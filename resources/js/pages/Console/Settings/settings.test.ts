import { describe, expect, it } from 'vitest'
import { errorsByLocale } from '@fapost/ui/components/localized-field'
import type { CommandState } from './types'
import {
  commandErrors,
  configFields,
  defaultConfig,
  emptyEntries,
  enumChoices,
  firstError,
  isEditableField,
  isTab,
  newCommand,
  refreshWarnings,
  tabsWithErrors,
  toPayload,
  visibleConfigFields,
  type SettingsFormData,
} from './settings'

const languages = ['en', 'ru']

function form(overrides: Partial<SettingsFormData> = {}): SettingsFormData {
  return {
    default_language: 'en',
    available_countries: ['UA'],
    default_flow_id: null,
    fallback_message: [
      { locale: 'en', text: 'Sorry' },
      { locale: 'ru', text: '  ' },
    ],
    busy_message: emptyEntries(languages),
    commands: [],
    settings: [{ key: 'tone', value: 'formal' }],
    ...overrides,
  }
}

describe('toPayload', () => {
  it('sends the localized texts as the languages that have text', () => {
    const payload = toPayload(form())

    expect(payload.fallback_message).toEqual({ en: 'Sorry' })
    expect(payload.busy_message).toEqual({})
    expect(payload.settings).toEqual([{ key: 'tone', value: 'formal' }])
  })

  it('sends each command with only the fields its type uses, without the list key', () => {
    const terminate = { ...newCommand(languages, 'a'), command: ' stop ', response: [{ locale: 'en', text: 'Bye' }], flowId: 'f-1' }
    const start = { ...newCommand(languages, 'b'), command: 'go', type: 'start_flow' as const, flowId: 'f-2', text: [{ locale: 'en', text: 'x' }] }
    const send = { ...newCommand(languages, 'c'), command: 'help', type: 'send_message' as const, text: [{ locale: 'ru', text: 'Помощь' }] }

    expect(toPayload(form({ commands: [terminate, start, send] })).commands).toEqual([
      { command: 'stop', type: 'terminate_session', response: { en: 'Bye' } },
      { command: 'go', type: 'start_flow', flow_id: 'f-2' },
      { command: 'help', type: 'send_message', text: { ru: 'Помощь' } },
    ])
  })
})

describe('toPayload with a Solution action', () => {
  it('sends the row as stored and nothing else', () => {
    const row: CommandState = {
      ...newCommand(languages, 'x'),
      command: ' thanks ',
      type: 'feedback.thank' as CommandState['type'],
      response: [{ locale: 'en', text: 'ignored' }],
      config: { note: 'x', nested: { a: 1 } },
      extension: { type: 'feedback.thank', label: 'Thank', installed: false },
    }

    expect(toPayload(form({ commands: [row] })).commands).toEqual([{ command: 'thanks', type: 'feedback.thank', config: { note: 'x', nested: { a: 1 } } }])
  })

  it('sends an empty config when none was stored', () => {
    const row: CommandState = { ...newCommand(languages, 'x'), command: 'thanks', config: null, extension: { type: 'feedback.thank', label: 'Thank', installed: true } }

    expect(toPayload(form({ commands: [row] })).commands).toEqual([{ command: 'thanks', type: 'feedback.thank', config: {} }])
  })
})

describe('toPayload with an editable Solution action', () => {
  it('sends its whole config and none of the built-in fields', () => {
    const row: CommandState = {
      ...newCommand(languages, 'x'),
      command: ' thanks ',
      type: 'feedback.thank',
      response: [{ locale: 'en', text: 'ignored' }],
      text: [{ locale: 'en', text: 'ignored' }],
      config: { text: 'Hi', hidden: { kept: true } },
    }

    expect(toPayload(form({ commands: [row] })).commands).toEqual([{ command: 'thanks', type: 'feedback.thank', config: { text: 'Hi', hidden: { kept: true } } }])
  })

  it('does not take a Solution action for a message', () => {
    const row: CommandState = { ...newCommand(languages, 'x'), command: 'thanks', type: 'feedback.thank', config: null }

    expect(toPayload(form({ commands: [row] })).commands).toEqual([{ command: 'thanks', type: 'feedback.thank' }])
  })
})

describe('config schema helpers', () => {
  const schema = {
    required: ['mode'],
    default_config: { mode: 'short' },
    sections: [
      { key: 'reply', label: 'Reply', fields: ['mode', 'text', 'undeclared'] },
      { key: 'more', label: 'More', fields: ['count'] },
    ],
    mode: { type: 'enum', label: 'Mode', options: { short: 'Short', long: 'Long' }, default: 'long' },
    text: { type: 'text', label: 'Text', default: '', visible_when: { mode: 'long' } },
    count: { type: 'number', label: 'Count' },
    payload: { type: 'json', label: 'Payload' },
  }

  it('lists the fields section after section, the required ones marked', () => {
    expect(configFields(schema).map((field) => [field.key, field.required])).toEqual([
      ['mode', true],
      ['text', false],
      ['count', false],
    ])
  })

  it('lists every field when no section is declared', () => {
    expect(configFields({ text: { type: 'string' }, payload: { type: 'json' } }).map((field) => field.key)).toEqual(['text', 'payload'])
    expect(configFields(undefined)).toEqual([])
  })

  it('shows a field only while its visible_when holds', () => {
    expect(visibleConfigFields(schema, { mode: 'short' }).map((field) => field.key)).toEqual(['mode', 'count'])
    expect(visibleConfigFields(schema, { mode: 'long' }).map((field) => field.key)).toEqual(['mode', 'text', 'count'])
  })

  it('starts from the field defaults with default_config over them', () => {
    expect(defaultConfig(schema)).toEqual({ mode: 'short', text: '' })
    expect(defaultConfig(undefined)).toEqual({})
  })

  it('reads enum choices from a map or a list', () => {
    expect(enumChoices({ key: 'mode', schema: schema.mode, required: true })).toEqual([
      { value: 'short', label: 'Short' },
      { value: 'long', label: 'Long' },
    ])
    expect(enumChoices({ key: 'tone', schema: { type: 'enum-cards', options: [{ value: 'warm', label: 'Warm', hint: 'Friendly' }, { value: 'dry' }] }, required: false })).toEqual([
      { value: 'warm', label: 'Warm' },
      { value: 'dry', label: 'dry' },
    ])
    expect(enumChoices({ key: 'size', schema: { type: 'enum', values: ['s', 'm'] }, required: false })).toEqual([
      { value: 's', label: 's' },
      { value: 'm', label: 'm' },
    ])
  })

  it('edits only the field types the form knows', () => {
    expect(isEditableField({ key: 'count', schema: schema.count, required: false })).toBe(true)
    expect(isEditableField({ key: 'payload', schema: schema.payload, required: false })).toBe(false)
  })
})

describe('refreshWarnings', () => {
  it('takes the warnings of the saved command with the same name and type', () => {
    const warning = { field: 'text', message: 'Long' }
    const edited: CommandState = { ...newCommand(languages, 'a'), command: ' thanks ', type: 'feedback.thank', warnings: [] }
    const other: CommandState = { ...newCommand(languages, 'b'), command: 'bye', type: 'feedback.thank', warnings: [warning] }
    const saved = [{ ...newCommand(languages, 'z'), command: 'thanks', type: 'feedback.thank', warnings: [warning] }]

    expect(refreshWarnings([edited, other], saved).map((row) => row.warnings)).toEqual([[warning], []])
  })
})

describe('toPayload settings', () => {
  it('drops the rows with neither key nor value', () => {
    const rows = [
      { key: 'tone', value: '' },
      { key: ' ', value: '  ' },
      { key: '', value: 'orphan' },
    ]

    expect(toPayload(form({ settings: rows })).settings).toEqual([
      { key: 'tone', value: '' },
      { key: '', value: 'orphan' },
    ])
  })
})

describe('newCommand', () => {
  it('starts as ending the session, with an empty text per language', () => {
    expect(newCommand(languages, 'k')).toEqual({
      key: 'k',
      command: '',
      type: 'terminate_session',
      response: [
        { locale: 'en', text: '' },
        { locale: 'ru', text: '' },
      ],
      flowId: null,
      flowMissing: false,
      text: [
        { locale: 'en', text: '' },
        { locale: 'ru', text: '' },
      ],
    })
  })
})

describe('tabsWithErrors', () => {
  it('maps each error to its tab, in the order of the tabs', () => {
    expect(
      tabsWithErrors({
        'settings.0.key': 'Duplicate',
        commands: 'Invalid',
        default_language: 'Required',
        'busy_message.ru': 'Unknown',
      }),
    ).toEqual(['general', 'commands', 'advanced'])
  })

  it('puts a command and the new flow command on the commands tab', () => {
    expect(tabsWithErrors({ 'commands.2.flow_id': 'Required' })).toEqual(['commands'])
    expect(tabsWithErrors({ command_index: 'Not a flow command' })).toEqual(['commands'])
  })

  it('ignores cleared errors', () => {
    expect(tabsWithErrors({ default_language: undefined })).toEqual([])
  })
})

describe('error helpers', () => {
  const errors = {
    'commands.1.command': 'Too long',
    'commands.1.response.ru': 'Unknown',
    'commands.10.type': 'Required',
    'available_countries.3': 'Invalid',
  }

  it('picks the errors of one command, relative to it', () => {
    expect(commandErrors(errors, 1)).toEqual({ command: 'Too long', 'response.ru': 'Unknown' })
    expect(errorsByLocale(commandErrors(errors, 1), 'response')).toEqual({ ru: 'Unknown' })
  })

  it('finds the first error of a field or its items', () => {
    expect(firstError(errors, 'available_countries')).toBe('Invalid')
    expect(firstError(errors, 'default_flow_id')).toBeUndefined()
  })
})

describe('isTab', () => {
  it('accepts only the three tabs', () => {
    expect(isTab('commands')).toBe(true)
    expect(isTab('billing')).toBe(false)
    expect(isTab(null)).toBe(false)
  })
})
