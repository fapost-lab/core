import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { BroadcasterClientOptions, EchoClientLike, EchoConnection } from '@fapost/ui/lib/live-updates'

/**
 * The console's Echo client. Loaded only through a dynamic import (see app.ts), so laravel-echo and pusher-js become a
 * chunk of their own that an installation without a broadcaster never downloads.
 *
 * The endpoint comes from the server at runtime rather than from VITE_* variables, so the prebuilt images work on any
 * domain: with no host, port or scheme given the socket opens on the page's own origin, where the bundled nginx forwards
 * `/app/` to Reverb.
 */
export function createEchoConnection(options: BroadcasterClientOptions): EchoConnection {
  const echo = createEcho(options)
  const connection = echo.connector.pusher.connection

  return {
    client: echo as unknown as EchoClientLike,
    onStateChange(callback) {
      callback(connection.state)
      connection.bind('state_change', (states: { current: string }) => callback(states.current))
    },
    disconnect() {
      echo.disconnect()
    },
  }
}

function createEcho(options: BroadcasterClientOptions): Echo<'reverb'> | Echo<'pusher'> {
  const secure = options.scheme ? options.scheme === 'https' : window.location.protocol === 'https:'
  const host = options.host ?? window.location.hostname
  // The page's own port only when the socket shares the page's host; another host listens on its scheme's default.
  const pagePort = options.host === null && window.location.port ? Number(window.location.port) : null
  const port = options.port ?? pagePort ?? (secure ? 443 : 80)

  const shared = {
    key: options.key,
    Pusher,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'] as ('ws' | 'wss')[],
    // The console page carries no csrf meta tag; Laravel accepts the XSRF-TOKEN cookie echoed back as a header. Read on
    // every authorization, so a token rotated by a later sign-in is the one sent.
    channelAuthorization: {
      endpoint: '/broadcasting/auth',
      transport: 'ajax' as const,
      headersProvider: () => ({ 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') }),
    },
  }

  if (options.driver === 'reverb') {
    return new Echo({ broadcaster: 'reverb', ...shared, wsHost: host, wsPort: port, wssPort: port })
  }

  // Hosted Pusher names its host by the cluster; a self-run Pusher-protocol server (Soketi) is given a host instead.
  if (options.host === null) {
    return new Echo({ broadcaster: 'pusher', ...shared, cluster: options.cluster ?? 'mt1', forceTLS: options.scheme !== 'http' })
  }

  return new Echo({ broadcaster: 'pusher', ...shared, cluster: options.cluster ?? 'mt1', wsHost: host, wsPort: port, wssPort: port })
}

function readCookie(name: string): string {
  const raw = document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`))
    ?.slice(name.length + 1)

  if (!raw) {
    return ''
  }

  try {
    return decodeURIComponent(raw)
  } catch {
    return raw
  }
}
