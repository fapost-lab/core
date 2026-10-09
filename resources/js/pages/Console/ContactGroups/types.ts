import type { ShellPageProps } from '@fapost/ui/shell'

export interface ContactGroupsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; description: string; contacts: string; created_at: string }
  fields: { name: string; description: string }
  search_label: string
  empty: string
  empty_hint: string
  delete_selected: string
  delete_one: { title: string; description: string }
  delete_many: { title: string; description: string }
}

export type ContactGroupsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { contact_groups: ContactGroupsTranslations }
  }
}

export interface ContactGroupRow extends Record<string, unknown> {
  id: string
  name: string
  description: string | null
  contactsCount: number
  createdAt: string | null
  editUrl: string
  deleteUrl: string
}
