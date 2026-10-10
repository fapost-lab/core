import type { ShellPageProps } from '@fapost/ui/shell'

interface Dialog {
  title: string
  description: string
}

export interface MediaTranslations {
  title: string
  description: string
  root: string
  folders: string
  no_folders: string
  new_folder: string
  upload: string
  breadcrumbs: string
  columns: { kind: string; name: string; size: string; references: string; created: string }
  filters: { kind: string; kind_all: string; trashed: string; trashed_without: string; trashed_with: string; trashed_only: string }
  in_trash: string
  search_label: string
  empty: string
  empty_hint: string
  actions_for: string
  folder_actions_for: string
  actions: { view: string; rename: string; move: string; references: string; delete: string; restore: string; force_delete: string; download: string }
  move_selected: string
  delete_selected: string
  upload_dialog: { title: string; description: string; files: string; folder: string; submit: string; too_many: string; too_large: string; progress: string }
  folder_dialog: { create_title: string; rename_title: string; name: string; parent: string }
  rename_dialog: { title: string; name: string }
  move_dialog: Dialog & { folder: string; submit: string }
  delete_one: Dialog & { referenced: string }
  delete_many: Dialog
  force_delete: Dialog
  delete_folder: { title: string; empty: string; has_contents: string; move_to: string; confirm: string }
  show: {
    back: string
    details: string
    kind: string
    mime: string
    size: string
    folder: string
    uploaded: string
    source: string
    download: string
    in_trash: string
    no_preview: string
    references: string
    references_hint: string
    references_none: string
    node: string
  }
  sources: Record<string, string>
}

export type MediaPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { media: MediaTranslations }
  }
}

/** A folder of the tree, in path order: a parent comes right before its subtree. */
export interface FolderNode {
  id: string
  name: string
  /** The folder's breadcrumb, `/Parent/Child`. */
  path: string
  parentId: string | null
  /** 0 for a folder at the root. */
  depth: number
  files: number
  folders: number
  updateUrl: string
  destroyUrl: string
}

export interface MediaRow extends Record<string, unknown> {
  id: string
  name: string
  kind: string
  kindLabel: string
  size: string
  references: number
  trashed: boolean
  createdAt: string | null
  folderId: string | null
  viewUrl: string
  updateUrl: string
  destroyUrl: string
  restoreUrl: string
  forceUrl: string
}

export interface Option {
  value: string
  label: string
}
