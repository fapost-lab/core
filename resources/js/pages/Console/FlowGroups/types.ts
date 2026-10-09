import type { ShellPageProps } from '@fapost/ui/shell'

export interface FlowGroupsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  edit_title: string
  columns: { name: string; flows: string }
  fields: { name: string }
  search_label: string
  empty: string
  empty_hint: string
  delete_selected: string
  delete_one: { title: string; description: string }
  delete_many: { title: string; description: string }
}

export type FlowGroupsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { flow_groups: FlowGroupsTranslations }
  }
}

export interface FlowGroupRow extends Record<string, unknown> {
  id: string
  name: string
  flowsCount: number
  editUrl: string
  deleteUrl: string
}
