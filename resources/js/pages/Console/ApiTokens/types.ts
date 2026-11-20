import type { ShellPageProps } from '@console/shell'

export interface ApiTokensTranslations {
  navigation: string
  title: string
  description: string
  address_note: string
  new: string
  columns: { name: string; token: string; abilities: string; issuer: string; expires: string; last_used: string; status: string }
  statuses: Record<TokenStatus, string>
  sensitive: string
  never_used: string
  issuer_inactive: string
  issuer_deleted: string
  search_label: string
  empty: string
  empty_hint: string
  actions: { rotate: string; revoke: string }
  issue: {
    title: string
    description: string
    submit: string
    name: string
    abilities: string
    abilities_hint: string
    abilities_placeholder: string
    abilities_selected: string
    nothing_found: string
    search: string
    remove: string
    expires: string
    expires_hint: string
  }
  rotate: { title: string; description: string; submit: string; abilities_hint: string }
  revoke: { title: string; description: string; confirm: string }
  reveal: { title: string; description: string; warning: string; copy: string; copied: string; done: string }
  issued: string
  rotated: string
  revoked: string
}

export type ApiTokensPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { api_tokens: ApiTokensTranslations }
  }
}

export type TokenStatus = 'active' | 'expired' | 'revoked'

export interface GrantablePermission {
  value: string
  label: string
  description: string
  sensitive: boolean
}

export interface TokenAbility {
  value: string
  label: string
  sensitive: boolean
}

export interface ApiTokenRow extends Record<string, unknown> {
  id: string
  name: string
  last_four: string
  abilities: TokenAbility[]
  issuer: { name: string; active: boolean } | null
  status: TokenStatus
  expires_at: string
  last_used_at: string | null
  created_at: string | null
  rotateUrl: string
  revokeUrl: string
}

/** What a dialog needs to draw its permission picker: the offered values with their labels. */
export interface PermissionOption {
  value: string
  label: string
}
