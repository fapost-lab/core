import type { ShellPageProps } from '@fapost/ui/shell'

export interface FlowsTranslations {
  title: string
  description: string
  new: string
  create_title: string
  create_and_open: string
  edit_title: string
  columns: { name: string; group: string; visibility: string; version: string }
  fields: {
    name: string
    group: string
    group_none: string
    description: string
    is_public: string
    is_public_hint: string
    logging_enabled: string
    logging_enabled_hint: string
  }
  new_group: string
  new_group_title: string
  new_group_name: string
  new_group_save: string
  search_label: string
  empty: string
  empty_hint: string
  filters: { group: string; group_all: string; active: string; active_all: string; active_yes: string; active_no: string }
  group_rows: string
  ungrouped: string
  status: { active: string; inactive: string }
  visibility: { public: string; private: string }
  no_version: string
  trigger_types: Record<string, string>
  builder: string
  builder_named: string
  actions_for: string
  activate: string
  deactivate: string
  activate_dialog: { title: string; description: string }
  deactivate_dialog: { title: string; description: string }
  delete_selected: string
  delete_one: { title: string; description: string }
  delete_many: { title: string; description: string }
}

export type FlowsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { flows: FlowsTranslations }
  }
}

export interface FlowTrigger {
  type: string
  text: string
}

export interface FlowRow extends Record<string, unknown> {
  id: string
  flowId: string
  name: string
  isActive: boolean
  isPublic: boolean
  groupId: string | null
  groupName: string | null
  publishedVersion: number | null
  trigger: FlowTrigger | null
  editUrl: string
  deleteUrl: string
  activityUrl: string
  builderUrl: string
}

export interface FlowGroupOption {
  id: string
  name: string
}
