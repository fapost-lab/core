import type { TableState } from '@fapost/ui/components/data-table'
import type { FolderNode } from './types'

/** The value of "the root" in a folder select: a select item cannot have an empty value. */
export const ROOT = '__root__'

/** The folder a select value names, `null` for the root. */
export function folderIdOf(value: unknown): string | null {
  return typeof value === 'string' && value !== '' && value !== ROOT ? value : null
}

/** Whether `candidate` is `folder` itself or lies inside it. */
export function isInSubtree(candidate: FolderNode, folder: FolderNode): boolean {
  return candidate.id === folder.id || candidate.path.startsWith(`${folder.path}/`)
}

/**
 * Where a folder's contents may go when it is deleted: any folder but itself and its own subtree (the server refuses
 * those too).
 */
export function moveTargets(tree: readonly FolderNode[], folder: FolderNode): FolderNode[] {
  return tree.filter((candidate) => !isInSubtree(candidate, folder))
}

/** A folder's name in a select: its whole path, so two folders with the same name are told apart. */
export function folderLabel(folder: FolderNode): string {
  return folder.path.replace(/^\//, '').split('/').join(' / ')
}

/**
 * The table state that opens a folder (`null`: the root), keeping the type and trash filters, the sort and the page
 * size; the search starts over, as it searched the folder that was open. `buildQuery` turns it into the query string.
 */
export function folderState(state: TableState, folderId: string | null): TableState {
  const filters = { ...(state.filters ?? {}) }

  if (folderId === null) {
    delete filters.folder
  } else {
    filters.folder = folderId
  }

  return { ...state, search: '', filters }
}

export type PreviewElement = 'image' | 'video' | 'audio' | 'embed' | null

/**
 * The element that previews a file, from the preview type the server resolved by extension: a document (a PDF) is
 * embedded with its MIME type, anything without a type has no preview.
 */
export function previewElement(type: string | null | undefined): PreviewElement {
  switch (type) {
    case 'image':
    case 'video':
    case 'audio':
      return type
    case 'document':
      return 'embed'
    default:
      return null
  }
}
