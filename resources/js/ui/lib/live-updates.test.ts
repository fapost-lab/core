import { afterEach, describe, expect, it, vi, type Mock } from 'vitest'
import {
  chooseTransport,
  connectBroadcaster,
  createAnnouncementReloader,
  currentEchoClient,
  disconnectBroadcaster,
  echoEventName,
  registerEchoClient,
  type EchoClientLike,
  type EchoConnection,
  type Timers,
} from './live-updates'

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
    expect(chooseTransport({ broadcasterEnabled: true, live, client, subscriptionFailed: true })).toBe('poll')
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

describe('connectBroadcaster', () => {
  afterEach(() => disconnectBroadcaster())

  const options = { driver: 'reverb' as const, key: 'app-key', cluster: null, host: null, port: null, scheme: null }

  /** A connection whose socket state the test drives. */
  function fakeConnection(): EchoConnection & { emit: (state: string) => void; disconnect: Mock<() => void> } {
    let listener: (state: string) => void = () => undefined
    let state = 'initialized'

    return {
      client,
      onStateChange(callback) {
        listener = callback
        callback(state)
      },
      disconnect: vi.fn<() => void>(),
      emit(next) {
        state = next
        listener(next)
      },
    }
  }

  it('never loads the client when nothing delivers or the browser has nowhere to connect', async () => {
    const load = vi.fn()

    await connectBroadcaster(undefined, load)
    await connectBroadcaster({ enabled: false, client: options }, load)
    await connectBroadcaster({ enabled: true, client: null }, load)
    await connectBroadcaster({ enabled: true, client: { ...options, key: '' } }, load)
    expect(load).not.toHaveBeenCalled()
    expect(currentEchoClient()).toBeNull()
  })

  it('keeps polling while the socket never connects', async () => {
    const connection = fakeConnection()
    const createEchoConnection = vi.fn(() => connection)

    await connectBroadcaster({ enabled: true, client: options }, async () => ({ createEchoConnection }))
    expect(createEchoConnection).toHaveBeenCalledWith(options)
    connection.emit('connecting')
    connection.emit('unavailable')
    expect(currentEchoClient()).toBeNull()
    expect(chooseTransport({ broadcasterEnabled: true, live, client: currentEchoClient() })).toBe('poll')
  })

  it('registers the client once connected, and goes back to polling when the socket is lost', async () => {
    const connection = fakeConnection()

    await connectBroadcaster({ enabled: true, client: options }, async () => ({ createEchoConnection: () => connection }))
    connection.emit('connected')
    expect(currentEchoClient()).toBe(client)

    for (const lost of ['unavailable', 'failed', 'disconnected']) {
      connection.emit('connected')
      connection.emit(lost)
      expect(currentEchoClient(), lost).toBeNull()
    }
  })

  it('leaves an unchanged connection alone and closes it when the prop says so', async () => {
    const connection = fakeConnection()
    const load = vi.fn(async () => ({ createEchoConnection: () => connection }))

    await connectBroadcaster({ enabled: true, client: options }, load)
    connection.emit('connected')
    await connectBroadcaster({ enabled: true, client: { ...options } }, load)
    expect(load).toHaveBeenCalledTimes(1)
    expect(currentEchoClient()).toBe(client)

    await connectBroadcaster({ enabled: true, client: null }, load)
    expect(connection.disconnect).toHaveBeenCalled()
    expect(currentEchoClient()).toBeNull()
  })

  it('stays on polling when the client fails to load', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)

    await connectBroadcaster({ enabled: true, client: options }, () => Promise.reject(new Error('chunk')))
    expect(currentEchoClient()).toBeNull()
    expect(warn).toHaveBeenCalled()
    warn.mockRestore()
  })
})
