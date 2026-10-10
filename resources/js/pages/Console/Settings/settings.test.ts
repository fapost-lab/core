import { describe, expect, it } from 'vitest'
import { commandErrors, emptyEntries, errorsUnder, firstError, isTab, newCommand, tabsWithErrors, toPayload, type SettingsFormData } from './settings'

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
    expect(errorsUnder(commandErrors(errors, 1), 'response')).toEqual({ ru: 'Unknown' })
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
