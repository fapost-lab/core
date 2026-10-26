import type { ShellPageProps } from '@console/shell'

export type HealthState = 'up' | 'down' | 'unknown' | 'disabled'

/** What every card carries, as `HealthPresenter` builds it; `checkedAt` is null for a service the report did not mention. */
interface CardBase {
  key: 'reverb' | 'gateway' | 'queues'
  state: HealthState
  /** Null for a service the report did not mention. */
  reason: string | null
  checkedAt: string | null
}

export interface ReverbCard extends CardBase {
  key: 'reverb'
  address: string | null
  connections: number | null
  channels: number | null
}

export interface GatewayCard extends CardBase {
  key: 'gateway'
  url: string | null
  /** Platform to its status (`matches`, `missing`, `differs`); null when the specs could not be read. */
  specs: { platform: string; status: string }[] | null
  /** Channels still on another ingress than the configured one; null when they could not be counted. */
  drift: { total: number; platforms: { platform: string; count: number }[] } | null
}

export interface QueueRow {
  name: string
  /** Seconds to clear the queue; null when no running supervisor serves it, which is not the same as an empty queue. */
  waitSeconds: number | null
  waitThreshold: number
  /** The wait has reached Horizon's long-wait threshold. */
  slow: boolean
  /** Null until Horizon has taken a snapshot to measure against. */
  jobsPerMinute: number | null
}

export interface QueuesCard extends CardBase {
  key: 'queues'
  /** Failed jobs inside Horizon's recent-failed window; null when they could not be counted. */
  failed: number | null
  failedWindowHours: number | null
  supervisors: number | null
  /** When the per-minute figures start, as an ISO time; null without a snapshot. */
  windowStartedAt: string | null
  /** Null when the queues could not be read. */
  queues: QueueRow[] | null
}

/** `installation` is the whole installation (single mode); `tenant` is one tenant's slice (host mode). */
export type HealthScope = 'installation' | 'tenant'

/** The tenant's slice carries no `queues`, and its Reverb and gateway cards leave the installation's figures null. */
export interface HealthReportData {
  reverb: ReverbCard
  gateway: GatewayCard
  queues?: QueuesCard
}

export interface HealthTranslations {
  navigation: string
  title: string
  description: string
  state: Record<HealthState | 'loading', string>
  summary: Record<HealthState | 'loading', string>
  hint: Record<'down' | 'unknown' | 'disabled', string>
  reverb: Record<'title' | 'subtitle' | 'description' | 'disabled' | 'connections' | 'channels' | 'address', string>
  gateway: Record<'title' | 'subtitle' | 'description' | 'disabled' | 'address' | 'specs' | 'specs_none' | 'drift' | 'drift_none' | 'drift_zero' | 'drift_some', string> & {
    spec_status: Record<'matches' | 'missing' | 'differs', string>
  }
  queues: Record<'title' | 'subtitle' | 'description' | 'disabled' | 'failed' | 'failed_any' | 'supervisors' | 'queue' | 'wait' | 'per_minute' | 'seconds' | 'slow' | 'queues_none' | 'window', string>
  /** The wording of the tenant's slice (host mode). */
  tenant: Record<'title' | 'description' | 'channels' | 'drift' | 'reason_withheld', string>
  reason: string
  not_reported: string
  no_figure: string
  checked: string
  never: string
  refresh: string
  refreshing: string
  polling: string
  loading: string
  stale: Record<'title' | 'body' | 'nodata' | 'retry', string>
}

export type HealthPageProps = ShellPageProps & {
  translations: { console: ShellPageProps['translations']['console'] & { health: HealthTranslations } }
}
