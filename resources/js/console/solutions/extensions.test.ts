import { describe, expect, it } from 'vitest'
import { actionHref, extensionsOn, rowValue, tabFromQuery, widgetGridClass, widgetSpanClass, type LinkAction } from './extensions'
import { toTableColumn } from './useSolutionColumns'

const link = (change: Partial<LinkAction>): LinkAction => ({ id: 'showcase.open', solution: 'showcase', key: 'open', order: 0, kind: 'link', label: 'Open', icon: null, ...change })

describe('reading the extensions prop', () => {
  it('gives the items on a target', () => {
    const items = [{ id: 'showcase.helper', solution: 'showcase', key: 'helper', order: 0, component: 'showcase::Shell/Helper' }]

    expect(extensionsOn({ shell: { floating: items, header: [] } }, 'shell', 'floating')).toBe(items)
  })

  it('gives nothing when the prop is missing or malformed', () => {
    expect(extensionsOn(null, 'shell', 'floating')).toEqual([])
    expect(extensionsOn(undefined, 'widgets', 'console')).toEqual([])
    expect(extensionsOn({ shell: null }, 'shell', 'floating')).toEqual([])
    expect(extensionsOn({ shell: { floating: 'x' } }, 'shell', 'floating')).toEqual([])
  })
})

describe('the link of a context action', () => {
  it('uses the link the server built on the record screen', () => {
    expect(actionHref(link({ href: '/assistant/a1/apps/showcase/contacts/c1' }), 'ignored')).toBe('/assistant/a1/apps/showcase/contacts/c1')
  })

  it('puts the record into the template, encoded', () => {
    expect(actionHref(link({ hrefTemplate: '/assistant/a1/apps/showcase/contacts/__record__' }), 'c 1/2')).toBe('/assistant/a1/apps/showcase/contacts/c%201%2F2')
  })

  it('has none without a link or a template', () => {
    expect(actionHref(link({}), 'c1')).toBeNull()
  })
})

describe('the widget grid', () => {
  it('spans a widget by its size in a two-column grid', () => {
    expect(widgetGridClass(2)).toContain('md:grid-cols-2')
    expect(widgetSpanClass('single', 2)).toBe('')
    expect(widgetSpanClass('double', 2)).toBe('md:col-span-2')
    expect(widgetSpanClass('full', 2)).toBe('md:col-span-2')
  })

  it('spans a widget by its size in a three-column grid', () => {
    expect(widgetGridClass(3)).toContain('xl:grid-cols-3')
    expect(widgetSpanClass('single', 3)).toBe('')
    expect(widgetSpanClass('double', 3)).toBe('sm:col-span-2')
    expect(widgetSpanClass('full', 3)).toBe('sm:col-span-2 xl:col-span-3')
  })
})

describe('the tab ?tab= opens', () => {
  const isCore = (value: string | null): value is 'general' | 'advanced' => value === 'general' || value === 'advanced'
  const solutionTabs = [{ id: 'showcase.general' }]

  it('opens a Core tab, a Solution tab, or the first', () => {
    expect(tabFromQuery('advanced', isCore, solutionTabs, 'general')).toBe('advanced')
    expect(tabFromQuery('showcase.general', isCore, solutionTabs, 'general')).toBe('showcase.general')
    expect(tabFromQuery(null, isCore, solutionTabs, 'general')).toBe('general')
  })

  it('opens the first for a Solution tab the user no longer sees', () => {
    expect(tabFromQuery('showcase.general', isCore, [], 'general')).toBe('general')
  })
})

describe('a Solution column in a table', () => {
  it('is keyed by its id, numbers aligned right', () => {
    const column = { id: 'showcase.score', solution: 'showcase', key: 'score', order: 0, label: 'Score', cell: 'number' as const, tones: {} }

    expect(toTableColumn(column)).toMatchObject({ key: 'showcase.score', label: 'Score', align: 'right' })
    expect(toTableColumn({ ...column, cell: 'status' })).not.toHaveProperty('align')
  })

  it('reads a row value a cell can show', () => {
    expect(rowValue({ 'showcase.score': 26 }, 'showcase.score')).toBe(26)
    expect(rowValue({ 'showcase.mood': 'happy' }, 'showcase.mood')).toBe('happy')
    expect(rowValue({}, 'showcase.score')).toBeNull()
    expect(rowValue({ 'showcase.score': { nested: true } }, 'showcase.score')).toBeNull()
  })
})
