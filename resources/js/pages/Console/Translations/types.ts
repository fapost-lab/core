import type { ShellPageProps } from '@fapost/ui/shell'

export type TranslationStatus = 'override' | 'inherited' | 'default'

export interface TranslationsTranslations {
  title: string
  description: string
  description_layered: string
  search_label: string
  columns: { group: string; key: string; description: string }
  filters: { group: string; group_all: string }
  status: Record<TranslationStatus, string>
  legend: Record<TranslationStatus, string>
  edit_named: string
  reset_named: string
  edit_dialog: { description: string; default_hint: string; inherited_hint: string }
  reset: { title: string; description: string; confirm: string }
  empty: string
  saved: string
  reset_done: string
}

export type TranslationsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { translations: TranslationsTranslations }
  }
}

/** One language of a key: what the bot sends now, where it comes from, and what the edit form needs. */
export interface TranslationCell {
  language: string
  value: string
  status: TranslationStatus
  /** The scope's own override, '' when it has none. */
  override: string
  /** The workspace's override under an assistant, null when there is none. */
  inherited: string | null
  default: string
}

export interface TranslationRow extends Record<string, unknown> {
  key: string
  group: string
  description: string
  hasOverride: boolean
  languages: TranslationCell[]
  updateUrl: string
  resetUrl: string
}
