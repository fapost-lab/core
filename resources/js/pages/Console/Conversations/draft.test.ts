import { afterEach, describe, expect, it, vi } from 'vitest'
import { canSend, exceedsSize, formatSize, newRequestId } from './draft'

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('newRequestId', () => {
  it('is a v4 uuid, different each time', () => {
    const a = newRequestId()

    expect(a).toMatch(V4)
    expect(newRequestId()).not.toBe(a)
  })

  it('builds a v4 uuid without randomUUID', () => {
    vi.stubGlobal('crypto', {
      getRandomValues: (bytes: Uint8Array) => {
        bytes.fill(255)

        return bytes
      },
    })

    expect(newRequestId()).toMatch(V4)
  })

  it('still builds one without any crypto', () => {
    vi.stubGlobal('crypto', undefined)

    expect(newRequestId()).toMatch(V4)
  })
})

describe('exceedsSize', () => {
  it('compares bytes with kilobytes', () => {
    expect(exceedsSize(1024, 1)).toBe(false)
    expect(exceedsSize(1025, 1)).toBe(true)
  })
})

describe('formatSize', () => {
  it('uses KB below a megabyte and MB above', () => {
    expect(formatSize(512)).toBe('512 KB')
    expect(formatSize(20480)).toBe('20 MB')
    expect(formatSize(2560)).toBe('2.5 MB')
  })
})

describe('canSend', () => {
  it('needs text or a file', () => {
    expect(canSend('  \n', false)).toBe(false)
    expect(canSend('hi', false)).toBe(true)
    expect(canSend('', true)).toBe(true)
  })
})
