import {computed, inject, type ComputedRef, type InjectionKey} from 'vue'

/**
 * Strings of the kit's own controls. The kit knows no host and no locale: the host application provides its strings
 * under `KIT_MESSAGES` (FaPost Core does it in its AppShell, from the console lang files), and every string it does
 * not provide falls back to the English default below. Placeholders are `:name`, filled with `interpolate`.
 */

/** What the server-driven DataTable and its pagination say. */
export interface DataTableLabels {
    search: string
    search_hint: string
    empty: string
    empty_search: string
    clear_search: string
    /** `:count` */
    selected: string
    clear_selection: string
    select_all: string
    /** `:name` */
    select_row: string
    actions: string
    /** `:column` */
    sort_by: string
    rows_per_page: string
    /** `:from`, `:to`, `:total` */
    range: string
    /** `:page`, `:last` */
    page: string
    previous: string
    next: string
    /** `:name` */
    expand_group: string
    /** `:name` */
    collapse_group: string
}

export interface KitMessages {
    /** The close button of dialogs and sheets. */
    close: string
    table: DataTableLabels
}

/** What a host provides: any part of the messages, read again on every render, so a locale switch shows at once. */
export type KitMessagesSource = () => {
    close?: string
    table?: Partial<DataTableLabels>
}

export const KIT_MESSAGES: InjectionKey<KitMessagesSource> = Symbol('fapost-ui:messages')

export const DEFAULT_KIT_MESSAGES: KitMessages = {
    close: 'Close',
    table: {
        search: 'Search',
        search_hint: 'Search…',
        empty: 'Nothing here yet.',
        empty_search: 'Nothing matches your search.',
        clear_search: 'Clear search',
        selected: 'Selected: :count',
        clear_selection: 'Clear selection',
        select_all: 'Select all rows on this page',
        select_row: 'Select :name',
        actions: 'Actions',
        sort_by: 'Sort by :column',
        rows_per_page: 'Rows per page',
        range: ':from–:to of :total',
        page: 'Page :page of :last',
        previous: 'Previous page',
        next: 'Next page',
        expand_group: 'Expand group :name',
        collapse_group: 'Collapse group :name',
    },
}

/** Merges what a host provides over the English defaults; a missing or empty string keeps its default. */
export function resolveKitMessages(provided: ReturnType<KitMessagesSource> | null | undefined): KitMessages {
    const table = { ...DEFAULT_KIT_MESSAGES.table }

    for (const [key, value] of Object.entries(provided?.table ?? {})) {
        if (key in table && typeof value === 'string' && value !== '') {
            table[key as keyof DataTableLabels] = value
        }
    }

    const close = provided?.close

    return { close: typeof close === 'string' && close !== '' ? close : DEFAULT_KIT_MESSAGES.close, table }
}

/** The kit's strings for a component: the host's, where it provides them, otherwise English. */
export function useKitMessages(): ComputedRef<KitMessages> {
    const source = inject(KIT_MESSAGES, null)

    return computed(() => resolveKitMessages(source?.()))
}
