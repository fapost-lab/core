import { afterEach, describe, expect, it, vi } from 'vitest'
import { isComponent, withTimeout } from './loader'

afterEach(() => {
  vi.useRealTimers()
})

describe('loading a Solution module', () => {
  it('passes a value that arrives in time', async () => {
    await expect(withTimeout(Promise.resolve('page'), 100, 'board.js')).resolves.toBe('page')
  })

  it('passes a failure through', async () => {
    await expect(withTimeout(Promise.reject(new Error('404')), 100, 'board.js')).rejects.toThrow('404')
  })

  it('gives up when the module takes too long', async () => {
    vi.useFakeTimers()
    const loading = withTimeout(new Promise<never>(() => {}), 10_000, 'board.js')
    const settled = expect(loading).rejects.toThrow('board.js did not load within 10000 ms')

    await vi.advanceTimersByTimeAsync(10_000)
    await settled
  })

  it('mounts only an object or a function as a component', () => {
    expect(isComponent({ setup: () => null })).toBe(true)
    expect(isComponent(() => null)).toBe(true)
    expect(isComponent(undefined)).toBe(false)
    expect(isComponent('Board')).toBe(false)
  })
})
