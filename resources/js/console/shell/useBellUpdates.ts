import { computed, shallowRef, toValue, watch, type ComputedRef, type MaybeRefOrGetter } from 'vue'
import { chooseTransport, currentEchoClient, echoEventName, type LiveChannel, type LiveTransport } from '../lib/live-updates'

export interface BellUpdatesOptions {
  /** The user's own channel from the shared `notifications` prop; without one the bell polls. */
  live: MaybeRefOrGetter<LiveChannel | null | undefined>
  /** Reads the count (or the list) again: on an announcement, on subscribing, and on every poll. */
  refresh: () => void
  /** Called instead of `refresh` when the server announces a change. */
  onAnnouncement: () => void
  pollMs: number
}

/**
 * Keeps the bell current between visits, like `useLiveUpdates` does for a screen but without Inertia reloads (the bell
 * reads JSON): listens on the user's own private channel while the server gives one and the app's socket is connected,
 * and polls every `pollMs` otherwise — including when the socket drops or the server refuses the subscription. On
 * subscribing it reads the count once, for whatever changed before the socket came up.
 *
 * Call it inside a component's setup (or an effect scope): the watcher, the timer and the subscription end with it.
 */
export function useBellUpdates(options: BellUpdatesOptions): { transport: ComputedRef<LiveTransport> } {
  /** The channel whose subscription the server refused (403/419): the bell polls for it from then on. */
  const refusedChannel = shallowRef<string | null>(null)

  const transport = computed<LiveTransport>(() => {
    const live = toValue(options.live)

    return chooseTransport({
      broadcasterEnabled: Boolean(live),
      live,
      client: currentEchoClient(),
      subscriptionFailed: refusedChannel.value !== null && refusedChannel.value === live?.channel,
    })
  })

  watch(
    [transport, () => toValue(options.live)?.channel, () => currentEchoClient()],
    (_value, _previous, onCleanup) => {
      const live = toValue(options.live)
      const client = currentEchoClient()

      if (transport.value === 'poll' || !live || client === null) {
        const timer = setInterval(options.refresh, options.pollMs)
        onCleanup(() => clearInterval(timer))

        return
      }

      const event = echoEventName(live.event)
      const channel = client.private(live.channel)
      channel.listen(event, options.onAnnouncement)
      channel.error?.(() => {
        refusedChannel.value = live.channel
      })
      options.refresh()

      onCleanup(() => {
        client.private(live.channel).stopListening?.(event)
        client.leave(live.channel)
      })
    },
    { immediate: true },
  )

  return { transport }
}
