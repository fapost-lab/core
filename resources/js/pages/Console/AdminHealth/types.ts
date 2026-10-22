import type { ShellPageProps } from '@console/shell'

export type HealthState = 'up' | 'down' | 'unknown' | 'disabled'

/** What every card carries, as `HealthPresenter` builds it; `checkedAt` is null for a service the report did not mention. */
interface CardBase {
  key: 'reverb' | 'gateway'
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

export interface HealthReportData {
  reverb: ReverbCard
  gateway: GatewayCard
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
