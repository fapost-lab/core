import { describe, expect, it } from 'vitest'
import { fallbackOptions, firstError, isTab, keptFallback, tabOfError, tabsWithErrors } from './form'

const options = [
  { value: 'en', label: 'English' },
  { value: 'ru', label: 'Russian' },
  { value: 'uk', label: 'Ukrainian' },
]

describe('tenant settings form', () => {
  it('knows its tabs', () => {
    expect(isTab('runtime')).toBe(true)
    expect(isTab('general')).toBe(false)
    expect(isTab(null)).toBe(false)
  })

  it('puts each error on the tab of its field', () => {
    expect(tabOfError('available_languages.1')).toBe('languages')
    expect(tabOfError('flow_session_ttl')).toBe('runtime')
    expect(tabOfError('broadcast_chunk_size')).toBe('broadcasts')
    expect(tabsWithErrors({ broadcast_chunk_size: 'x', fallback_language: 'y', flow_session_ttl: undefined })).toEqual(['languages', 'broadcasts'])
  })

  it('offers only available languages as the fallback', () => {
    expect(fallbackOptions(options, ['ru', 'en']).map((option) => option.value)).toEqual(['en', 'ru'])
    expect(fallbackOptions(options, [])).toEqual(options)
  })

  it('clears a fallback that is no longer available', () => {
    expect(keptFallback('ru', ['en', 'ru'])).toBe('ru')
    expect(keptFallback('ru', ['en'])).toBe('')
    expect(keptFallback('ru', [])).toBe('ru')
  })

  it('finds the first error of a field or its items', () => {
    expect(firstError({ 'available_languages.0': 'bad' }, 'available_languages')).toBe('bad')
    expect(firstError({}, 'available_languages')).toBeUndefined()
  })
})
