import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { registerEchoClient, type EchoClientLike, type LiveChannel } from '../lib/live-updates'
import { useBellUpdates } from './useBellUpdates'

const live: LiveChannel = { channel: 'tenant.t.user.u.notifications', event: 'staff.notifications' }

/** An Echo client whose channel lets the test announce and refuse the subscription. */
function fakeClient() {
  let onError: (status: unknown) => void = () => undefined
  let onEvent: () => void = () => undefined
  const listen = vi.fn((_event: string, callback: () => void) => {
    onEvent = callback
  })

  const client: EchoClientLike & { listen: typeof listen; leave: Mock<(channel: string) => void> } = {
    private: () => ({
      listen,
      stopListening: vi.fn(),
      error(callback) {
        onError = callback
      },
    }),
    leave: vi.fn<(channel: string) => void>(),
    listen,
  }

  return { client, announce: () => onEvent(), refuse: () => onError({ status: 403 }) }
}

describe('useBellUpdates', () => {
  let scope: EffectScope
  let refresh: Mock<() => void>
  let onAnnouncement: Mock<() => void>
  const channel = shallowRef<LiveChannel | null>(live)

  beforeEach(() => {
    vi.useFakeTimers()
    refresh = vi.fn()
    onAnnouncement = vi.fn()
    channel.value = live
    scope = effectScope()
  })

  afterEach(() => {
    scope.stop()
    registerEchoClient(null)
    vi.useRealTimers()
  })

  function mount() {
    return scope.run(() => useBellUpdates({ live: channel, refresh, onAnnouncement, pollMs: 60_000 }))!
  }

  it('polls every minute while no socket is connected', () => {
    const { transport } = mount()

    expect(transport.value).toBe('poll')
    vi.advanceTimersByTime(60_000)
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('polls when the server gives no channel, even with a socket', () => {
    channel.value = null
    registerEchoClient(fakeClient().client)
    const { transport } = mount()

    expect(transport.value).toBe('poll')
  })

  it('listens on the users channel once connected, stops polling, and catches up once', async () => {
    const { client, announce } = fakeClient()
    const { transport } = mount()

    registerEchoClient(client)
    await nextTick()

    expect(transport.value).toBe('echo')
    expect(client.listen).toHaveBeenCalledWith('.staff.notifications', expect.any(Function))
    expect(refresh).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(180_000)
    expect(refresh).toHaveBeenCalledTimes(1)

    announce()
    expect(onAnnouncement).toHaveBeenCalledTimes(1)
  })

  it('goes back to polling when the socket drops or the subscription is refused', async () => {
    const first = fakeClient()
    mount()
    registerEchoClient(first.client)
    await nextTick()

    registerEchoClient(null)
    await nextTick()
    expect(first.client.leave).toHaveBeenCalledWith(live.channel)
    refresh.mockClear()
    vi.advanceTimersByTime(60_000)
    expect(refresh).toHaveBeenCalledTimes(1)

    const second = fakeClient()
    registerEchoClient(second.client)
    await nextTick()
    second.refuse()
    await nextTick()
    refresh.mockClear()
    vi.advanceTimersByTime(60_000)
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('leaves the channel and stops the timer when the bell goes away', async () => {
    const { client } = fakeClient()
    registerEchoClient(client)
    mount()
    await nextTick()

    scope.stop()
    expect(client.leave).toHaveBeenCalledWith(live.channel)
  })
})
