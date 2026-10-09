/**
 * The query-string side of the data table: what the server reads (`search`, `sort`, `per_page`, `page`; see
 * App\Http\DataTable\DataTable) and how a click on the table turns into the next query. Kept free of Vue and Inertia
 * so it is tested on its own.
 */

/** The state the server echoes back: the values it actually applied. */
export interface TableState {
    search: string
    /** A column key, with a leading `-` for descending. */
    sort: string
    perPage: number
}

export interface TableMeta {
    total: number
    perPage: number
    currentPage: number
    lastPage: number
    from: number | null
    to: number | null
}

export interface TableDefaults {
    sort: string
    perPage: number
}

export type SortDirection = 'asc' | 'desc'

export type TableQuery = Partial<{ search: string; sort: string; per_page: number; page: number }>

/** The direction the table is sorted by this column in, or null when it is sorted by another one. */
export function sortDirection(sort: string, column: string): SortDirection | null {
    if (sort === column) {
        return 'asc'
    }

    return sort === `-${column}` ? 'desc' : null
}

/** What a click on a column header sorts by next: ascending first, then flipping between the two. */
export function nextSort(sort: string, column: string): string {
    return sortDirection(sort, column) === 'asc' ? `-${column}` : column
}

/**
 * The query for a state, without what is the default (the URL stays short and the first visit has no query at all)
 * and always back on the first page: a change to the search, the sort or the page size starts over.
 * `page` is only sent when it is asked for.
 */
export function buildQuery(state: TableState, defaults: TableDefaults, page = 1): TableQuery {
    const query: TableQuery = {}

    const search = state.search.trim()

    if (search !== '') {
        query.search = search
    }

    if (state.sort !== defaults.sort) {
        query.sort = state.sort
    }

    if (state.perPage !== defaults.perPage) {
        query.per_page = state.perPage
    }

    if (page > 1) {
        query.page = page
    }

    return query
}

/** Whether every row of the page, some of them or none are selected, for the header checkbox. */
export function selectionState(selected: readonly string[], rowIds: readonly string[]): boolean | 'indeterminate' {
    const count = rowIds.filter((id) => selected.includes(id)).length

    if (count === 0) {
        return false
    }

    return count === rowIds.length ? true : 'indeterminate'
}

/** The selection after one row is ticked or cleared. */
export function toggleRow(selected: readonly string[], id: string, checked: boolean): string[] {
    const rest = selected.filter((value) => value !== id)

    return checked ? [...rest, id] : rest
}

/** The selection after the header checkbox: every row of the page, or none. */
export function toggleAll(rowIds: readonly string[], checked: boolean): string[] {
    return checked ? [...rowIds] : []
}

/** The selected ids that are still on the page, in selection order. */
export function keepVisible(selected: readonly string[], rowIds: readonly string[]): string[] {
    return selected.filter((id) => rowIds.includes(id))
}
