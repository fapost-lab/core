import { describe, expect, it } from 'vitest'
import { hitValue, isPaletteShortcut, searchable } from './search'

const key = (k: string, mods: Partial<Record<'metaKey' | 'ctrlKey' | 'altKey' | 'shiftKey', boolean>> = {}) => ({
  key: k,
  metaKey: false,
  ctrlKey: false,
  altKey: false,
  shiftKey: false,
  ...mods,
})

describe('searchable', () => {
  it('asks only from two characters on, trimmed', () => {
    expect(searchable('')).toBeNull()
    expect(searchable('  a ')).toBeNull()
    expect(searchable(' ab ')).toBe('ab')
  })

  it('counts characters, not bytes', () => {
    expect(searchable('я')).toBeNull()
    expect(searchable('яб')).toBe('яб')
  })
})

describe('isPaletteShortcut', () => {
  it('opens on Cmd+K and Ctrl+K', () => {
    expect(isPaletteShortcut(key('k', { metaKey: true }))).toBe(true)
    expect(isPaletteShortcut(key('K', { ctrlKey: true }))).toBe(true)
  })

  it('ignores a plain K and other combinations', () => {
    expect(isPaletteShortcut(key('k'))).toBe(false)
    expect(isPaletteShortcut(key('k', { metaKey: true, shiftKey: true }))).toBe(false)
    expect(isPaletteShortcut(key('j', { ctrlKey: true }))).toBe(false)
  })
})

describe('hitValue', () => {
  it('keeps the same id in two groups apart', () => {
    const hit = { id: '01H', title: 'x', subtitle: null, url: '/x', external: false }

    expect(hitValue({ key: 'users', items: [hit] }, hit)).not.toBe(hitValue({ key: 'roles', items: [hit] }, hit))
  })
})
