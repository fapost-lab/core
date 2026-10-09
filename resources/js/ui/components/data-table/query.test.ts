import {describe, expect, it} from 'vitest'
import {buildQuery, keepVisible, nextSort, selectionState, sortDirection, toggleAll, toggleRow} from './query'

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
