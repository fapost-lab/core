import { describe, expect, it } from 'vitest'
import { errorsByLocale, filledLocales, localizedRecord, localesWithErrors, setLocalizedText, tabToReveal, textOf } from './localized'

const entries = [
  { locale: 'ru', text: 'Привет' },
  { locale: 'en', text: '' },
  { locale: 'uk', text: '   \n' },
]

describe('filledLocales', () => {
  it('lists the locales that have text', () => {
    expect([...filledLocales(entries)]).toEqual(['ru'])
  })
})

describe('textOf', () => {
  it('gives the text of a locale, or an empty one', () => {
    expect(textOf(entries, 'ru')).toBe('Привет')
    expect(textOf(entries, 'de')).toBe('')
  })
})

describe('setLocalizedText', () => {
  it('replaces the text of a locale in place', () => {
    expect(setLocalizedText(entries, 'en', 'Hi')).toEqual([
      { locale: 'ru', text: 'Привет' },
      { locale: 'en', text: 'Hi' },
      { locale: 'uk', text: '   \n' },
    ])
  })

  it('adds a locale the list lacks at the end', () => {
    expect(setLocalizedText([{ locale: 'ru', text: 'a' }], 'en', 'b')).toEqual([
      { locale: 'ru', text: 'a' },
      { locale: 'en', text: 'b' },
    ])
  })

  it('does not change the list it was given', () => {
    const current = [{ locale: 'ru', text: 'a' }]

    setLocalizedText(current, 'ru', 'b')

    expect(current).toEqual([{ locale: 'ru', text: 'a' }])
  })
})

describe('tabToReveal', () => {
  it('opens the first tab with an error, in the order of the tabs', () => {
    expect(tabToReveal({ uk: 'x', en: 'y' }, ['ru', 'en', 'uk'], 'ru')).toBe('en')
  })

  it('keeps the current tab when it has an error itself', () => {
    expect(tabToReveal({ uk: 'x', en: 'y' }, ['ru', 'en', 'uk'], 'uk')).toBeNull()
  })

  it('keeps the current tab when no language has an error', () => {
    expect(tabToReveal({ de: 'x' }, ['ru', 'en'], 'en')).toBeNull()
  })
})

describe('localizedRecord', () => {
  it('keeps the locales that have text, untrimmed', () => {
    expect(localizedRecord(entries)).toEqual({ ru: 'Привет' })
    expect(localizedRecord([{ locale: 'en', text: ' Hello ' }])).toEqual({ en: ' Hello ' })
  })

  it('is empty when nothing is written', () => {
    expect(localizedRecord([{ locale: 'en', text: '' }])).toEqual({})
    expect(localizedRecord([])).toEqual({})
  })
})

describe('errorsByLocale', () => {
  it('keys the errors under a field by locale and leaves the rest out', () => {
    expect(errorsByLocale({ 'message.ru': 'x', 'message.en': 'y', message: 'z', 'messages.ru': 'q', name: 'n' }, 'message')).toEqual({ ru: 'x', en: 'y' })
  })
})

describe('localesWithErrors', () => {
  it('lists the locales with an error in the order of the tabs', () => {
    expect(localesWithErrors({ uk: 'x', ru: 'y', de: 'z' }, ['ru', 'en', 'uk'])).toEqual(['ru', 'uk'])
  })
})
