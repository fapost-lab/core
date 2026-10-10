import type { ShellPageProps } from '@fapost/ui/shell'
import type { SelectOption } from '../Settings/types'

export type { SelectOption }

export type TenantSettingsTab = 'languages' | 'runtime' | 'broadcasts'

interface Section {
  title: string
  description: string
}

export interface TenantSettingsTranslations {
  title: string
  description: string
  saved: string
  tabs: Record<TenantSettingsTab, string>
  sections: { languages: Section; messaging: Section; flow: Section; broadcasts: Section }
  fields: {
    content_base_language: string
    content_base_language_help: string
    content_base_language_locked: string
    available_languages: string
    available_languages_help: string
    fallback_language: string
    fallback_language_help: string
    messaging_rate_limit: string
    flow_session_ttl: string
    flow_session_ttl_help: string
    max_retry_attempts: string
    flow_fallback_message: string
    flow_fallback_message_help: string
    broadcast_chunk_size: string
    broadcast_backpressure: string
    broadcast_backpressure_help: string
    language_placeholder: string
    languages_placeholder: string
    languages_selected: string
    search: string
    nothing_found: string
    remove: string
  }
  errors: { has_errors: string }
}

export type TenantSettingsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { tenant_settings: TenantSettingsTranslations }
  }
}

/** The tenant's settings as stored; the form holds and sends the same fields. */
export interface TenantSettingsState {
  content_base_language: string
  available_languages: string[]
  fallback_language: string
  messaging_rate_limit: number
  broadcast_chunk_size: number
  broadcast_backpressure: boolean
  flow_session_ttl: number
  max_retry_attempts: number
  flow_fallback_message: string
}
