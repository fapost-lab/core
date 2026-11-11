import type { ShellPageProps } from '@console/shell'

export interface FlowTemplatesTranslations {
  navigation: string
  title: string
  description: string
  new: string
  create_title: string
  create_and_open: string
  columns: { name: string; version: string; used_by: string }
  sections: { general: { title: string; description: string } }
  fields: { name: string; description: string }
  search_label: string
  empty: string
  empty_hint: string
  no_version: string
  not_used: string
  builder: string
  builder_named: string
  actions_for: string
  delete_selected: string
  delete_one: { title: string; description: string }
  delete_many: { title: string; description: string }
}

export type FlowTemplatesPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { flow_templates: FlowTemplatesTranslations }
  }
}

export interface FlowTemplateRow extends Record<string, unknown> {
  id: string
  flowId: string
  name: string
  description: string | null
  publishedVersion: number | null
  usedBy: number
  deleteUrl: string
  builderUrl: string
}
