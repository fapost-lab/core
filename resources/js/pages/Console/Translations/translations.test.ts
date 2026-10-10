import { describe, expect, it } from 'vitest'
import { cellOf, fallbackOf, formValues, statusVariant, truncate } from './translations'
import type { TranslationRow } from './types'

const row: TranslationRow = {
  key: 'errors.fallback',
  group: 'errors',
  description: 'Fallback',
  hasOverride: true,
  updateUrl: '/u',
  resetUrl: '/r',
  languages: [
    { language: 'en', value: 'Own', status: 'override', override: 'Own', inherited: 'Tenant', default: 'Default' },
    { language: 'ru', value: 'Тенант', status: 'inherited', override: '', inherited: 'Тенант', default: 'Системное' },
    { language: 'uk', value: 'Системне', status: 'default', override: '', inherited: null, default: 'Системне' },
  ],
}

describe('translations', () => {
  it('starts the form from the own overrides only', () => {
    expect(formValues(row)).toEqual({ en: 'Own', ru: '', uk: '' })
  })

  it('falls back to the workspace text before the catalog default', () => {
    expect(fallbackOf(row.languages[0])).toBe('Tenant')
    expect(fallbackOf(row.languages[2])).toBe('Системне')
  })

  it('marks only cells that are not the default', () => {
    expect(statusVariant('override')).toBe('warning')
    expect(statusVariant('inherited')).toBe('info')
    expect(statusVariant('default')).toBeNull()
  })

  it('finds the cell of a language', () => {
    expect(cellOf(row, 'ru')?.value).toBe('Тенант')
    expect(cellOf(row, 'de')).toBeUndefined()
  })

  it('shortens long text', () => {
    expect(truncate('a'.repeat(70))).toBe(`${'a'.repeat(60)}…`)
    expect(truncate('short')).toBe('short')
  })
})
