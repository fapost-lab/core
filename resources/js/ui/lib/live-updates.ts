import { shallowRef } from 'vue'

/**
 * The plain parts of live updates, without Inertia: which way a screen stays current, the Echo client the app may
 * register, and the reload schedule that follows an announcement. `useLiveUpdates` puts them together.
 */

/** The part of a Laravel Echo channel that live updates use. */
export interface EchoChannelLike {
  listen(event: string, callback: () => void): unknown
  stopListening?(event: string): unknown
  /** Called when the server refuses the subscription (e.g. 403 or 419 from `/broadcasting/auth`). */
  error?(callback: (status: unknown) => void): unknown
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
 * The Echo client, while its connection is up. `connectBroadcaster` registers it from a lazily loaded chunk once the
 * socket reaches `connected`, and takes it away again when the socket is lost; without it every screen polls. Reactive,
 * so a screen moves over to the websocket when the connection comes up and back to polling when it goes.
 */
const echoClient = shallowRef<EchoClientLike | null>(null)

export function registerEchoClient(client: EchoClientLike | null): void {
  echoClient.value = client
}

export function currentEchoClient(): EchoClientLike | null {
  return echoClient.value
}

/**
 * Where the browser reaches the websocket server, as the server hands it over (BroadcasterStatus::client). Both drivers
 * speak the Pusher protocol. A null host, port or scheme means the page's own origin.
 */
export interface BroadcasterClientOptions {
  driver: 'reverb' | 'pusher'
  key: string
  cluster: string | null
  host: string | null
  port: number | null
  scheme: 'http' | 'https' | null
}

/** The shared `broadcaster` prop. */
export interface BroadcasterProp {
  enabled: boolean
  name?: string
  client?: BroadcasterClientOptions | null
}

/** A live websocket connection: the client to listen with, and the state of its socket (Pusher's connection states). */
export interface EchoConnection {
  client: EchoClientLike
  /** Calls back with the current state at once, then on every change. */
  onStateChange(callback: (state: string) => void): void
  disconnect(): void
}

/** Builds the Echo connection; the app passes a dynamic import so the client and its transport stay out of the main bundle. */
export type EchoClientLoader = () => Promise<{ createEchoConnection: (options: BroadcasterClientOptions) => EchoConnection }>

/** States in which the socket delivers nothing: the client is taken away and the screens poll until it reconnects. */
const LOST_STATES = ['unavailable', 'failed', 'disconnected']

let activeConnection: { signature: string; connection: EchoConnection | null } | null = null

/**
 * Keeps the app's Echo connection in step with the `broadcaster` prop: opens it when the server says a broadcaster
 * delivers and tells the browser where (the prop is null for a guest), closes it when that stops being true or the
 * endpoint changes, and does nothing when nothing changed — so it can run on every Inertia navigation. The chunk is never
 * fetched on an installation that polls. The client is registered only while the socket is `connected`; a chunk that
 * fails to load, a socket that never connects or one that drops leaves the screens polling.
 */
export async function connectBroadcaster(broadcaster: BroadcasterProp | null | undefined, load: EchoClientLoader): Promise<void> {
  const options = broadcaster?.enabled && broadcaster.client?.key ? broadcaster.client : null
  const signature = options ? JSON.stringify(options) : null

  if ((activeConnection?.signature ?? null) === signature) {
    return
  }

  disconnectBroadcaster()

  if (!options || !signature) {
    return
  }

  const current: { signature: string; connection: EchoConnection | null } = { signature, connection: null }
  activeConnection = current

  try {
    const { createEchoConnection } = await load()

    if (activeConnection !== current) {
      return
    }

    const connection = createEchoConnection(options)
    current.connection = connection
    connection.onStateChange((state) => {
      if (activeConnection !== current) {
        return
      }

      if (state === 'connected') {
        registerEchoClient(connection.client)
      } else if (LOST_STATES.includes(state)) {
        registerEchoClient(null)
      }
    })
  } catch (error) {
    if (activeConnection === current) {
      activeConnection = null
    }

    console.warn('Live updates fall back to polling: the Echo client did not load.', error)
  }
}

/** Closes the app's Echo connection, if any; every screen polls afterwards. */
export function disconnectBroadcaster(): void {
  const previous = activeConnection
  activeConnection = null
  registerEchoClient(null)
  previous?.connection?.disconnect()
}

/**
 * Echo only when all of these hold: the server has a broadcaster that delivers, the screen was given a channel, the app
 * has a connected client to listen with, and the server did not refuse the screen's subscription. Anything less polls,
 * so a screen never waits for announcements that cannot come.
 */
export function chooseTransport(input: {
  broadcasterEnabled: boolean
  live: LiveChannel | null | undefined
  client: EchoClientLike | null
  subscriptionFailed?: boolean
}): LiveTransport {
  return input.broadcasterEnabled && Boolean(input.live?.channel) && input.client !== null && !input.subscriptionFailed ? 'echo' : 'poll'
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
