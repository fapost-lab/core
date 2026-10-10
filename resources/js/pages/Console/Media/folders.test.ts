import { describe, expect, it } from 'vitest'
import { folderIdOf, folderLabel, folderState, isInSubtree, moveTargets, previewElement, ROOT } from './folders'
import type { FolderNode } from './types'

function node(id: string, path: string): FolderNode {
  return { id, name: path.split('/').pop() ?? id, path, parentId: null, depth: path.split('/').length - 2, files: 0, folders: 0, updateUrl: '', destroyUrl: '' }
}

const docs = node('a', '/Docs')
const docs2024 = node('b', '/Docs/2024')
const docsArchive = node('c', '/Docs Archive')
const images = node('d', '/Images')
const tree = [docs, docs2024, docsArchive, images]

describe('folders', () => {
  it('reads the root sentinel and an empty value as no folder', () => {
    expect(folderIdOf(ROOT)).toBeNull()
    expect(folderIdOf('')).toBeNull()
    expect(folderIdOf(undefined)).toBeNull()
    expect(folderIdOf('a')).toBe('a')
  })

  it('tells the subtree by path segment, not by prefix', () => {
    expect(isInSubtree(docs2024, docs)).toBe(true)
    expect(isInSubtree(docs, docs)).toBe(true)
    expect(isInSubtree(docsArchive, docs)).toBe(false)
  })

  it('offers every folder but the deleted one and its subtree as a target', () => {
    expect(moveTargets(tree, docs).map((folder) => folder.id)).toEqual(['c', 'd'])
  })

  it('labels a folder by its path', () => {
    expect(folderLabel(docs2024)).toBe('Docs / 2024')
  })

  it('opens a folder keeping the other filters and dropping the search', () => {
    const state = { search: 'logo', sort: 'name', perPage: 25, filters: { kind: 'image', folder: 'a' } }

    expect(folderState(state, 'd')).toEqual({ search: '', sort: 'name', perPage: 25, filters: { kind: 'image', folder: 'd' } })
    expect(folderState(state, null)).toEqual({ search: '', sort: 'name', perPage: 25, filters: { kind: 'image' } })
  })

  it('previews by the resolved type', () => {
    expect(previewElement('image')).toBe('image')
    expect(previewElement('document')).toBe('embed')
    expect(previewElement(null)).toBeNull()
    expect(previewElement('other')).toBeNull()
  })
})
