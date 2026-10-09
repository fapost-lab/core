export interface DataTableColumn {
    /** The key of the row's value. */
    key: string
    /** The `sort` value of a sortable column, when the server names it differently from the row's key. */
    sortKey?: string
    label: string
    sortable?: boolean
    align?: 'left' | 'center' | 'right'
    /** Classes for the header and the cells, for a width or `hidden md:table-cell`. */
    class?: string
}
