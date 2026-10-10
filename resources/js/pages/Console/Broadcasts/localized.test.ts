import { describe, expect, it } from 'vitest'
import { cleanMessage, filledLocales, localesWithErrors } from './localized'

const entries = [
  { locale: 'ru', text: 'Привет' },
  { locale: 'en', text: '' },
  { locale: 'uk', text: '   \n' },
]

describe('cleanMessage', () => {
  it('keeps the languages that have text', () => {
    expect(cleanMessage(entries)).toEqual({ ru: 'Привет' })
  })

  it('does not trim the text it keeps', () => {
    expect(cleanMessage([{ locale: 'en', text: ' Hello ' }])).toEqual({ en: ' Hello ' })
  })

  it('is empty when nothing is written', () => {
    expect(cleanMessage([{ locale: 'en', text: '' }])).toEqual({})
    expect(cleanMessage([])).toEqual({})
  })
})

describe('filledLocales', () => {
  it('lists the tabs that have text', () => {
    expect([...filledLocales(entries)]).toEqual(['ru'])
  })
})

describe('localesWithErrors', () => {
  it('returns the locales with an error in the order of the tabs', () => {
    expect(localesWithErrors({ 'message.uk': 'x', 'message.ru': 'y', name: 'z' }, ['ru', 'en', 'uk'])).toEqual(['ru', 'uk'])
  })

  it('returns nothing when no language has an error', () => {
    expect(localesWithErrors({ name: 'z', message: 'q' }, ['ru', 'en'])).toEqual([])
  })
})
