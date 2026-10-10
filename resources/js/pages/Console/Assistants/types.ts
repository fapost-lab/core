import type { ShellPageProps } from '@fapost/ui/shell'

export interface AssistantsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; active: string; language: string; updated: string }
  sections: { general: { title: string; description: string } }
  fields: {
    name: string
    default_language: string
    language_placeholder: string
    is_active: string
    is_active_help: string
    created: string
    updated: string
  }
  status: { active: string; inactive: string }
  details: string
  channels: string
  channels_hint: string
  manage: string
  view: string
  back: string
  search_label: string
  actions_for: string
  empty: string
  empty_hint: string
  delete_one: { title: string; description: string }
}

export type AssistantsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { assistants: AssistantsTranslations }
  }
}

export interface AssistantRow extends Record<string, unknown> {
  id: string
  name: string
  isActive: boolean
  defaultLanguage: string
  languageLabel: string
  updatedAt: string | null
  showUrl: string
  editUrl: string
  consoleUrl: string
  can: { view: boolean; update: boolean }
}

export interface LanguageOption {
  value: string
  label: string
}

/** What the assistant form edits. */
export interface AssistantFields {
  name: string
  defaultLanguage: string
  isActive: boolean
}
