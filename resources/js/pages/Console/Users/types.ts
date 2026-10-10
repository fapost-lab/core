import type { ShellPageProps } from '@fapost/ui/shell'

interface Section {
  title: string
  description: string
}

interface Dialog {
  title: string
  description: string
}

export interface UsersTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  invite_hint: string
  columns: { name: string; email: string; phone: string; status: string; active: string; roles: string }
  support: string
  you: string
  active: string
  blocked: string
  actions_for: string
  actions: { activate: string; deactivate: string; resend_activation: string }
  sections: { profile: Section; access: Section; password: Section }
  fields: {
    name: string
    email: string
    phone: string
    password: string
    role: string
    role_hint: string
    roles: string
    current_roles: string
    kept_roles: string
    no_roles: string
  }
  roles_locked: string
  search_label: string
  empty: string
  empty_hint: string
  delete_selected: string
  delete_user: string
  delete_one: Dialog
  delete_many: Dialog
  deactivate: Dialog & { confirm: string }
  activate: Dialog & { confirm: string }
}

export type UsersPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { users: UsersTranslations }
  }
}

export interface RoleOption {
  value: string
  label: string
}

export interface UserRow extends Record<string, unknown> {
  id: string
  name: string
  email: string
  phone: string | null
  status: string
  statusLabel: string
  isActive: boolean
  isSupport: boolean
  isSelf: boolean
  roles: string[]
  can: { edit: boolean; deactivate: boolean; activate: boolean; resend: boolean }
  editUrl: string
  activityUrl: string
  resendUrl: string
}

export interface EditableUser {
  id: string
  name: string
  email: string
  phone: string | null
  roles: string[]
  keptRoles: string[]
}
