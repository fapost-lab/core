import { computed, onBeforeUnmount, toValue, watch, type ComputedRef, type MaybeRefOrGetter } from 'vue'
import { router, usePage, usePoll } from '@inertiajs/vue3'
import { chooseTransport, createAnnouncementReloader, currentEchoClient, echoEventName, type LiveChannel, type LiveTransport } from './live-updates'

/** Longer than the server's throttle window (FlowActivityNotifier::THROTTLE_SECONDS), see createAnnouncementReloader. */
const TRAILING_RELOAD_MS = 3000

/**
 * On the websocket a screen still reloads this often: each reload tells the server someone is watching (the mark lapses
 * after FlowActivityWatchers::TTL_SECONDS, longer than this), and it picks up anything an announcement missed.
 */
const HEARTBEAT_MS = 120_000

export interface LiveUpdatesOptions {
  /** The channel the server gave the screen; without one the screen polls. */
  live: MaybeRefOrGetter<LiveChannel | null | undefined>
  /** The props a reload asks for (a partial reload), e.g. `['table']`. */
  only: string[]
  /** How often to poll when there is no live channel. */
  pollMs: number
  /** Whether to stay current at all (a finished session's page has nothing more to wait for). Defaults to always. */
  active?: MaybeRefOrGetter<boolean>
}

/**
 * Keeps a screen current: listens on the screen's private channel through Echo when the server has a broadcaster that
 * delivers and the app has an Echo client, and polls with Inertia otherwise. Either way it reloads only `only`.
 *
 * Returns which way it works, for a "live" or "refreshes every N s" hint.
 */
export function useLiveUpdates(options: LiveUpdatesOptions): { transport: ComputedRef<LiveTransport> } {
  const page = usePage<{ broadcaster?: { enabled: boolean } }>()

  const transport = computed<LiveTransport>(() =>
    chooseTransport({
      broadcasterEnabled: Boolean(page.props.broadcaster?.enabled),
      live: toValue(options.live),
      client: currentEchoClient(),
    }),
  )
  const active = computed(() => (options.active === undefined ? true : toValue(options.active)))

  const poll = usePoll(options.pollMs, { only: options.only }, { autoStart: false })
  const reloader = createAnnouncementReloader(() => router.reload({ only: options.only }), TRAILING_RELOAD_MS)

  watch(
    [transport, active, () => toValue(options.live)?.channel],
    (_value, _previous, onCleanup) => {
      poll.stop()

      if (!active.value) {
        return
      }

      const live = toValue(options.live)
      const client = currentEchoClient()

      if (transport.value === 'poll' || !live || client === null) {
        poll.start()

        return
      }

      const event = echoEventName(live.event)
      client.private(live.channel).listen(event, reloader.notify)
      const heartbeat = setInterval(() => router.reload({ only: options.only }), HEARTBEAT_MS)

      onCleanup(() => {
        clearInterval(heartbeat)
        reloader.dispose()
        client.private(live.channel).stopListening?.(event)
        client.leave(live.channel)
      })
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    poll.stop()
    reloader.dispose()
  })

  return { transport }
}
