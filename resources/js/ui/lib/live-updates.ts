import { shallowRef } from 'vue'

/**
 * The plain parts of live updates, without Inertia: which way a screen stays current, the Echo client the app may
 * register, and the reload schedule that follows an announcement. `useLiveUpdates` puts them together.
 */

/** The part of a Laravel Echo channel that live updates use. */
export interface EchoChannelLike {
  listen(event: string, callback: () => void): unknown
  stopListening?(event: string): unknown
}

/** The part of a Laravel Echo instance that live updates use; any Pusher-protocol server works behind it. */
export interface EchoClientLike {
  private(channel: string): EchoChannelLike
  leave(channel: string): void
}

/** What the server tells a screen to listen to (see FlowActivityChannel). */
export interface LiveChannel {
  channel: string
  event: string
}

export type LiveTransport = 'echo' | 'poll'

/**
 * The Echo client, once the app has one. Nothing registers it yet: the client ships later as a lazily loaded chunk, and
 * until then every screen polls. Reactive, so a screen that mounted before the client arrived moves over to it.
 */
const echoClient = shallowRef<EchoClientLike | null>(null)

export function registerEchoClient(client: EchoClientLike | null): void {
  echoClient.value = client
}

export function currentEchoClient(): EchoClientLike | null {
  return echoClient.value
}

/**
 * Echo only when all three hold: the server has a broadcaster that delivers, the screen was given a channel, and the app
 * has a client to listen with. Anything less polls, so a screen never waits for announcements that cannot come.
 */
export function chooseTransport(input: { broadcasterEnabled: boolean; live: LiveChannel | null | undefined; client: EchoClientLike | null }): LiveTransport {
  return input.broadcasterEnabled && Boolean(input.live?.channel) && input.client !== null ? 'echo' : 'poll'
}

/** Echo prefixes an event name with its namespace unless the name starts with a dot; the server names it exactly. */
export function echoEventName(event: string): string {
  return event.startsWith('.') ? event : `.${event}`
}

export interface Timers {
  set: (callback: () => void, ms: number) => unknown
  clear: (handle: unknown) => void
}

const defaultTimers: Timers = {
  set: (callback, ms) => setTimeout(callback, ms),
  clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
}

/**
 * What a screen does when it hears an announcement: reload at once, and once more `trailingMs` after the last one. The
 * server announces at most once per throttle window, so a change made inside the window only shows through the second
 * reload; `trailingMs` is therefore longer than that window.
 */
export function createAnnouncementReloader(reload: () => void, trailingMs: number, timers: Timers = defaultTimers): { notify: () => void; dispose: () => void } {
  let trailing: unknown = null

  function clearTrailing(): void {
    if (trailing !== null) {
      timers.clear(trailing)
      trailing = null
    }
  }

  return {
    notify(): void {
      reload()
      clearTrailing()
      trailing = timers.set(() => {
        trailing = null
        reload()
      }, trailingMs)
    },
    dispose(): void {
      clearTrailing()
    },
  }
}
