import type { ShellPageProps } from '@console/shell'

export interface CredentialsTranslations {
  navigation: string
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; type: string; hosts: string; secret: string }
  secret_set: string
  sections: {
    general: { title: string; description: string }
    secret: { title: string; description: string }
    hosts: { title: string; description: string }
  }
  fields: {
    name: string
    type: string
    type_locked: string
    hosts: string
    hosts_placeholder: string
    hosts_remove: string
    secret_saved: string
    secret_placeholder: string
    secret_changed: string
    choose: string
  }
  warnings: { api_key_query: string }
  type_hint: string
  search_label: string
  empty: string
  empty_hint: string
  delete_one: { title: string; description: string }
  oauth: {
    title: string
    description: string
    statuses: { not_connected: string; connected: string; reconnect_needed: string }
    connected_by: string
    connected_at: string
    refreshed: string
    reconnect_hint: string
    failure: string
    callback_url: string
    callback_hint: string
    copy: string
    copied: string
    connect: string
    reconnect: string
    disconnect: string
    disconnect_confirm: { title: string; description: string }
    save_first: string
    params_hint: string
  }
}

export type CredentialsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { credentials: CredentialsTranslations }
  }
}

export type CredentialFieldKind = 'text' | 'url' | 'select'

export interface CredentialTypeField {
  key: string
  label: string
  secret: boolean
  required: boolean
  kind: CredentialFieldKind
  choices: { value: string; label: string }[]
}

/** A credential type as the server describes it: the form renders its fields, it holds no literal of any type. */
export interface CredentialTypeSchema {
  id: string
  label: string
  fields: CredentialTypeField[]
}

export interface CredentialRow extends Record<string, unknown> {
  id: string
  name: string
  type: string
  type_label: string
  allowed_hosts: string[]
  secret_changed_at: string
  editUrl: string
  deleteUrl: string
}

/** What the edit form starts from. No secret is ever in it. */
export interface EditableCredential {
  id: string
  name: string
  type: string
  type_label: string
  settings: Record<string, string>
  allowed_hosts: string[]
  secret_changed_at: string
}

export type OAuthStatus = 'not_connected' | 'connected' | 'reconnect_needed'

/** The connection of an authorization-code credential; the page gets null for every other type. */
export interface OAuthConnectionState {
  status: OAuthStatus
  connected_at: string | null
  connected_by_name: string | null
  refreshed_at: string | null
  failure_code: string | null
  callback_url: string
  urls: { connect: string; disconnect: string }
}
