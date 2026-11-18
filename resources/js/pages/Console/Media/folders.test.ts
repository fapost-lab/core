import { describe, expect, it } from 'vitest'
import { folderIdOf, folderLabel, folderState, isInSubtree, moveTargets, previewElement, ROOT } from './folders'
import type { FolderNode } from './types'

function node(id: string, path: string, parentId: string | null = null): FolderNode {
  return { id, name: path.split('/').pop() ?? id, path, parentId, depth: path.split('/').length - 2, files: 0, folders: 0, updateUrl: '', destroyUrl: '' }
}

const docs = node('a', '/Docs')
const docs2024 = node('b', '/Docs/2024', 'a')
const docsArchive = node('c', '/Docs Archive')
const images = node('d', '/Images')
const twin = node('e', '/Docs')
const twinChild = node('f', '/Docs/2024', 'e')
const tree = [docs, docs2024, docsArchive, images]
const twinTree = [docs, docs2024, twin, twinChild]

describe('folders', () => {
  it('reads the root sentinel and an empty value as no folder', () => {
    expect(folderIdOf(ROOT)).toBeNull()
    expect(folderIdOf('')).toBeNull()
    expect(folderIdOf(undefined)).toBeNull()
    expect(folderIdOf('a')).toBe('a')
  })

  it('tells the subtree by parent, not by path', () => {
    expect(isInSubtree(docs2024, docs, tree)).toBe(true)
    expect(isInSubtree(docs, docs, tree)).toBe(true)
    expect(isInSubtree(docsArchive, docs, tree)).toBe(false)
    expect(isInSubtree(twin, docs, twinTree)).toBe(false)
    expect(isInSubtree(twinChild, docs, twinTree)).toBe(false)
  })

  it('keeps a same-named sibling and its subtree as targets', () => {
    expect(moveTargets(twinTree, docs).map((folder) => folder.id)).toEqual(['e', 'f'])
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
