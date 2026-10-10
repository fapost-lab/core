import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { effectScope, nextTick, reactive, type EffectScope } from 'vue'
import { registerEchoClient, type EchoClientLike } from './live-updates'
import { useLiveUpdates } from './useLiveUpdates'

const inertia = vi.hoisted(() => ({
  props: {} as { broadcaster?: { enabled: boolean } },
  poll: { start: vi.fn(), stop: vi.fn() },
  reload: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: inertia.props }),
  usePoll: () => inertia.poll,
  router: { reload: inertia.reload },
}))

const live = { channel: 'tenant.t.assistant.a.flow', event: 'flow.activity' }

/** An Echo client whose channel lets the test refuse the subscription. */
function fakeClient(): EchoClientLike & { refuse: () => void; listen: Mock<(event: string, callback: () => void) => void>; leave: Mock<(channel: string) => void> } {
  let onError: (status: unknown) => void = () => undefined
  const listen = vi.fn<(event: string, callback: () => void) => void>()

  return {
    private: () => ({
      listen,
      stopListening: vi.fn(),
      error(callback) {
        onError = callback
      },
    }),
    leave: vi.fn<(channel: string) => void>(),
    listen,
    refuse: () => onError({ status: 403 }),
  }
}

describe('useLiveUpdates', () => {
  let scope: EffectScope

  beforeEach(() => {
    inertia.props = reactive({ broadcaster: { enabled: true } })
    inertia.poll.start.mockClear()
    inertia.poll.stop.mockClear()
    scope = effectScope()
  })

  afterEach(() => {
    scope.stop()
    registerEchoClient(null)
  })

  function mount() {
    return scope.run(() => useLiveUpdates({ live, only: ['table'], pollMs: 5000 }))!
  }

  it('polls while no socket ever connects', () => {
    const { transport } = mount()

    expect(transport.value).toBe('poll')
    expect(inertia.poll.start).toHaveBeenCalled()
  })

  it('listens once connected and goes back to polling when the connection is lost', async () => {
    const client = fakeClient()
    const { transport } = mount()

    registerEchoClient(client)
    await nextTick()
    expect(transport.value).toBe('echo')
    expect(client.listen).toHaveBeenCalledWith('.flow.activity', expect.any(Function))

    inertia.poll.start.mockClear()
    registerEchoClient(null)
    await nextTick()
    expect(transport.value).toBe('poll')
    expect(client.leave).toHaveBeenCalledWith(live.channel)
    expect(inertia.poll.start).toHaveBeenCalled()
  })

  it('polls when the server refuses the subscription', async () => {
    const client = fakeClient()
    registerEchoClient(client)
    const { transport } = mount()
    expect(transport.value).toBe('echo')

    inertia.poll.start.mockClear()
    client.refuse()
    await nextTick()
    expect(transport.value).toBe('poll')
    expect(client.leave).toHaveBeenCalledWith(live.channel)
    expect(inertia.poll.start).toHaveBeenCalled()
  })
})
