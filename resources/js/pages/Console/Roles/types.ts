import type { ShellPageProps } from '@fapost/ui/shell'

interface Dialog {
  title: string
  description: string
}

export interface RolesTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; display_name: string; system: string; permissions: string }
  system: string
  custom: string
  sections: { general: { title: string; description: string } }
  fields: { name: string; name_system_hint: string; display_name: string }
  select_all: string
  clear_all: string
  selected_count: string
  sensitive: string
  search_label: string
  empty: string
  empty_hint: string
  delete_selected: string
  delete_role: string
  delete_one: Dialog
  delete_many: Dialog
}

export type RolesPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { roles: RolesTranslations }
  }
}

export interface PermissionOption {
  value: string
  label: string
  description: string
  sensitive: boolean
}

export interface PermissionGroup {
  key: string
  label: string
  permissions: PermissionOption[]
}

export interface RoleRow extends Record<string, unknown> {
  id: string
  name: string
  displayName: string | null
  isSystem: boolean
  permissionsCount: number
  editUrl: string
}

export interface EditableRole {
  name: string
  displayName: string | null
  isSystem: boolean
  permissions: string[]
}
