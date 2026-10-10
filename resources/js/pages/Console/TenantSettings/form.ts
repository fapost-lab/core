import type { SelectOption, TenantSettingsTab } from './types'

const TABS: TenantSettingsTab[] = ['languages', 'runtime', 'broadcasts']

const FIELD_TABS: Record<string, TenantSettingsTab> = {
  content_base_language: 'languages',
  available_languages: 'languages',
  fallback_language: 'languages',
  messaging_rate_limit: 'runtime',
  flow_session_ttl: 'runtime',
  max_retry_attempts: 'runtime',
  flow_fallback_message: 'runtime',
  broadcast_chunk_size: 'broadcasts',
  broadcast_backpressure: 'broadcasts',
}

export function isTab(value: unknown): value is TenantSettingsTab {
  return typeof value === 'string' && (TABS as string[]).includes(value)
}

/** The tab a validation error belongs to, by the field the server reports it under (`available_languages.0` too). */
export function tabOfError(key: string): TenantSettingsTab {
  return FIELD_TABS[key.split('.')[0]] ?? 'languages'
}

/** The tabs that hold an error, in the order of the tabs, so the page can mark them and open the first. */
export function tabsWithErrors(errors: Record<string, string | undefined>): TenantSettingsTab[] {
  const tabs = new Set(Object.keys(errors).filter((key) => errors[key] !== undefined).map(tabOfError))

  return TABS.filter((tab) => tabs.has(tab))
}

/** The fallback language is one of the available ones; with none chosen yet, any language is offered, as before. */
export function fallbackOptions(options: SelectOption[], available: string[]): SelectOption[] {
  return available.length === 0 ? options : options.filter((option) => available.includes(option.value))
}

/** The fallback once the available languages change: kept while still available, otherwise cleared for a new choice. */
export function keptFallback(fallback: string, available: string[]): string {
  return available.length === 0 || available.includes(fallback) ? fallback : ''
}

/** The first error among a field and its items (`available_languages`, `available_languages.0`). */
export function firstError(errors: Record<string, string | undefined>, field: string): string | undefined {
  return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
}
