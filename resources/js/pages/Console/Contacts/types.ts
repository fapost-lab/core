import type { ShellPageProps } from '@fapost/ui/shell'

export interface ContactsTranslations {
  title: string
  description: string
  search_label: string
  columns: { id: string; platform: string; external_id: string; name: string; language: string; created_at: string }
  filters: { platform: string; platform_all: string; language: string; language_all: string }
  platforms: Record<string, string>
  open: string
  open_named: string
  copy: string
  copied: string
  empty: string
  empty_hint: string
  back: string
  view: {
    identity: string
    profile: string
    from_platform: string
    platform: string
    external_id: string
    language: string
    username: string
    created_at: string
    updated_at: string
    tags: string
    groups: string
    no_tags: string
    no_groups: string
    manage_tags: string
    manage_groups: string
    empty_value: string
  }
  tags_dialog: { title: string; description: string; placeholder: string; add: string; remove_named: string }
  groups_dialog: { title: string; description: string; search: string; empty: string }
}

export type ContactsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { contacts: ContactsTranslations }
  }
}

export interface ContactRow extends Record<string, unknown> {
  id: string
  shortId: string
  platform: string
  externalId: string
  name: string | null
  language: string | null
  createdAt: string | null
  viewUrl: string
}

export interface ContactCardField {
  key: string
  value: string
}

export interface ContactCardGroup {
  key: string
  fieldsLabel: string
  fields: ContactCardField[]
}

export interface ContactCardData {
  profile: ContactCardField[]
  groups: ContactCardGroup[]
  meta: ContactCardField[]
  collapsed: boolean
}

export interface ContactGroupRef {
  id: string
  name: string
}

export interface ContactDetails {
  id: string
  platform: string
  externalId: string
  language: string | null
  username: string | null
  name: string | null
  createdAt: string | null
  updatedAt: string | null
  tags: string[]
  groups: ContactGroupRef[]
}
