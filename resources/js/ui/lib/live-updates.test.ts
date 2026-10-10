import { afterEach, describe, expect, it, vi } from 'vitest'
import { chooseTransport, createAnnouncementReloader, currentEchoClient, echoEventName, registerEchoClient, type EchoClientLike, type Timers } from './live-updates'

const client: EchoClientLike = {
  private: () => ({ listen: () => undefined }),
  leave: () => undefined,
}

const live = { channel: 'tenant.t.assistant.a.flow', event: 'flow.activity' }

describe('chooseTransport', () => {
  it('listens through Echo only with a delivering broadcaster, a channel and a client', () => {
    expect(chooseTransport({ broadcasterEnabled: true, live, client })).toBe('echo')
  })

  it('polls when any of the three is missing', () => {
    expect(chooseTransport({ broadcasterEnabled: false, live, client })).toBe('poll')
    expect(chooseTransport({ broadcasterEnabled: true, live: null, client })).toBe('poll')
    expect(chooseTransport({ broadcasterEnabled: true, live: { channel: '', event: 'flow.activity' }, client })).toBe('poll')
    expect(chooseTransport({ broadcasterEnabled: true, live, client: null })).toBe('poll')
  })
})

describe('registerEchoClient', () => {
  afterEach(() => registerEchoClient(null))

  it('has no client until the app registers one', () => {
    expect(currentEchoClient()).toBeNull()
    registerEchoClient(client)
    expect(currentEchoClient()).toBe(client)
  })
})

describe('echoEventName', () => {
  it('keeps the server name exact by prefixing a dot once', () => {
    expect(echoEventName('flow.activity')).toBe('.flow.activity')
    expect(echoEventName('.flow.activity')).toBe('.flow.activity')
  })
})

describe('createAnnouncementReloader', () => {
  function fakeTimers(): Timers & { run: () => void; pending: () => number } {
    let queue: Array<{ id: number; callback: () => void }> = []
    let next = 0

    return {
      set: (callback) => {
        const id = ++next
        queue.push({ id, callback })

        return id
      },
      clear: (handle) => {
        queue = queue.filter((entry) => entry.id !== handle)
      },
      run: () => {
        const due = queue
        queue = []
        due.forEach((entry) => entry.callback())
      },
      pending: () => queue.length,
    }
  }

  it('reloads at once and once more after the window', () => {
    const reload = vi.fn()
    const timers = fakeTimers()
    const reloader = createAnnouncementReloader(reload, 3000, timers)

    reloader.notify()
    expect(reload).toHaveBeenCalledTimes(1)

    timers.run()
    expect(reload).toHaveBeenCalledTimes(2)
    expect(timers.pending()).toBe(0)
  })

  it('keeps a single trailing reload for a burst of announcements', () => {
    const reload = vi.fn()
    const timers = fakeTimers()
    const reloader = createAnnouncementReloader(reload, 3000, timers)

    reloader.notify()
    reloader.notify()
    expect(reload).toHaveBeenCalledTimes(2)
    expect(timers.pending()).toBe(1)

    timers.run()
    expect(reload).toHaveBeenCalledTimes(3)
  })

  it('drops the trailing reload when disposed', () => {
    const reload = vi.fn()
    const timers = fakeTimers()
    const reloader = createAnnouncementReloader(reload, 3000, timers)

    reloader.notify()
    reloader.dispose()
    timers.run()
    expect(reload).toHaveBeenCalledTimes(1)
  })
})
