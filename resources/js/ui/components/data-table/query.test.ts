import {describe, expect, it} from 'vitest'
import {buildQuery, groupRuns, pageWindow, visibleRows, keepVisible, nextSort, selectionState, sortDirection, toggleAll, toggleRow} from './query'

const defaults = {sort: 'name', perPage: 25}

describe('sortDirection', () => {
    it('reads the direction from the sign', () => {
        expect(sortDirection('name', 'name')).toBe('asc')
        expect(sortDirection('-name', 'name')).toBe('desc')
    })

    it('is null for another column', () => {
        expect(sortDirection('name', 'created_at')).toBeNull()
        expect(sortDirection('-name_extra', 'name')).toBeNull()
    })
})

describe('nextSort', () => {
    it('sorts a new column ascending', () => {
        expect(nextSort('name', 'created_at')).toBe('created_at')
    })

    it('flips between ascending and descending on the same column', () => {
        expect(nextSort('name', 'name')).toBe('-name')
        expect(nextSort('-name', 'name')).toBe('name')
    })
})

describe('buildQuery', () => {
    it('is empty for the defaults', () => {
        expect(buildQuery({search: '', sort: 'name', perPage: 25}, defaults)).toEqual({})
    })

    it('carries what differs from the defaults', () => {
        expect(buildQuery({search: ' vip ', sort: '-created_at', perPage: 50}, defaults)).toEqual({
            search: 'vip',
            sort: '-created_at',
            per_page: 50,
        })
    })

    it('drops a blank search', () => {
        expect(buildQuery({search: '   ', sort: 'name', perPage: 25}, defaults)).toEqual({})
    })

    it('sends the page only beyond the first', () => {
        expect(buildQuery({search: '', sort: 'name', perPage: 25}, defaults, 1)).toEqual({})
        expect(buildQuery({search: '', sort: 'name', perPage: 25}, defaults, 3)).toEqual({page: 3})
    })
})

describe('selection', () => {
    const rows = ['a', 'b', 'c']

    it('reports none, some and all', () => {
        expect(selectionState([], rows)).toBe(false)
        expect(selectionState(['a'], rows)).toBe('indeterminate')
        expect(selectionState(['a', 'b', 'c'], rows)).toBe(true)
    })

    it('ignores ids that are no longer on the page', () => {
        expect(selectionState(['gone'], rows)).toBe(false)
    })

    it('does not report an empty page as all selected', () => {
        expect(selectionState([], [])).toBe(false)
    })

    it('ticks and clears one row without duplicating it', () => {
        expect(toggleRow(['a'], 'b', true)).toEqual(['a', 'b'])
        expect(toggleRow(['a', 'b'], 'b', true)).toEqual(['a', 'b'])
        expect(toggleRow(['a', 'b'], 'a', false)).toEqual(['b'])
    })

    it('ticks and clears the whole page', () => {
        expect(toggleAll(rows, true)).toEqual(rows)
        expect(toggleAll(rows, false)).toEqual([])
    })

    it('keeps only the selected ids still on the page', () => {
        expect(keepVisible(['a', 'gone', 'c'], rows)).toEqual(['a', 'c'])
    })
})

describe('buildQuery with filters and a grouping', () => {
    const grouped = {sort: 'name', perPage: 25, group: ''}

    it('leaves the keys out when the table declares none', () => {
        expect(buildQuery({search: '', sort: 'name', perPage: 25}, defaults)).toEqual({})
    })

    it('carries the filters that have a value, trimmed', () => {
        expect(buildQuery({search: '', sort: 'name', perPage: 25, filters: {group: ' abc ', active: ''}, group: ''}, grouped)).toEqual({
            filter: {group: 'abc'},
        })
    })

    it('reads the empty list the server sends for no filters', () => {
        expect(buildQuery({search: '', sort: 'name', perPage: 25, filters: [] as unknown as Record<string, string>, group: ''}, grouped)).toEqual({})
    })

    it('sends the grouping unless it is the default one', () => {
        expect(buildQuery({search: '', sort: 'name', perPage: 25, filters: {}, group: 'group'}, grouped)).toEqual({group: 'group'})
        expect(buildQuery({search: '', sort: 'name', perPage: 25, filters: {}, group: ''}, grouped)).toEqual({})
    })
})

describe('groupRuns', () => {
    const key = (row: {g: string}): string => row.g

    it('is empty for no rows', () => {
        expect(groupRuns([], key)).toEqual([])
    })

    it('gathers neighbours with the same key, in order', () => {
        const rows = [{g: 'a'}, {g: 'a'}, {g: 'b'}, {g: ''}]

        expect(groupRuns(rows, key).map((run) => [run.key, run.rows.length])).toEqual([
            ['a', 2],
            ['b', 1],
            ['', 1],
        ])
    })

    it('starts a new run when a key comes up again after another', () => {
        expect(groupRuns([{g: 'a'}, {g: 'b'}, {g: 'a'}], key).map((run) => run.key)).toEqual(['a', 'b', 'a'])
    })
})

describe('visibleRows', () => {
    const runs = [
        {key: 'a', label: 'A', rows: [1, 2]},
        {key: 'b', label: 'B', rows: [3]},
    ]

    it('leaves out the rows of collapsed runs', () => {
        expect(visibleRows(runs, [])).toEqual([1, 2, 3])
        expect(visibleRows(runs, ['a'])).toEqual([3])
    })

    it('never collapses a run without a header', () => {
        expect(visibleRows([{key: '', label: null, rows: [1, 2]}], [''])).toEqual([1, 2])
    })
})

describe('pageWindow', () => {
    it('lists every page of a short list', () => {
        expect(pageWindow(2, 5)).toEqual([1, 2, 3, 4, 5])
        expect(pageWindow(1, 1)).toEqual([1])
    })

    it('skips the middle of a long list', () => {
        expect(pageWindow(1, 121)).toEqual([1, 2, 3, 4, null, 121])
        expect(pageWindow(60, 121)).toEqual([1, null, 59, 60, 61, null, 121])
        expect(pageWindow(121, 121)).toEqual([1, null, 118, 119, 120, 121])
    })

    it('does not mark a gap of one page', () => {
        expect(pageWindow(5, 9)).toEqual([1, null, 4, 5, 6, null, 9])
        expect(pageWindow(4, 9)).toEqual([1, 2, 3, 4, 5, null, 9])
    })
})
