<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useDebounceFn } from '@vueuse/core'
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronDown, ChevronRight, Inbox, Search, SearchX, X } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Checkbox } from '@fapost/ui/components/checkbox'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Input } from '@fapost/ui/components/input'
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@fapost/ui/components/table'
import { cn } from '@fapost/ui/lib/utils'
import { interpolate } from '@fapost/ui/shell'
import type { ShellPageProps } from '@fapost/ui/shell'
import DataTablePagination from './DataTablePagination.vue'
import {
  buildQuery,
  groupRuns,
  keepVisible,
  nextSort,
  selectionState,
  sortDirection,
  toggleAll,
  toggleRow,
  visibleRows,
  type TableDefaults,
  type TableMeta,
  type TableState,
} from './query'
import type { DataTableColumn } from './types'

/**
 * A list screen's table, driven by the server (App\Http\DataTable\DataTable): search, sort, page size and page live in
 * the query string, every change is an Inertia visit to `url` that keeps the page's state and scroll and replaces the
 * history entry, and the rows, `meta`, `state` and `defaults` come back as props, as the server returns them.
 *
 * Slots:
 *  - `cell-<key>` (row)   : the content of a cell, instead of the row's value;
 *  - `actions` (row)      : the last cell of a row, for its buttons;
 *  - `toolbar`            : beside the search, for a "New" button and the screen's filters; gets `filters` (the applied
 *                           ones), `setFilter(key, value)` (an empty value clears it), `group` (the key of the grouping
 *                           applied, '') and `setGroup(key)`; each change starts over on the first page;
 *  - `bulk-actions` (selected, clear): shown while rows are ticked (needs `selectable`);
 *  - `empty` (searching)  : the empty state, instead of the plain text; `searching` tells a search that found
 *                           nothing from a list that is empty.
 *
 * Grouping: when the server has applied a grouping (`state.group` is set) and the screen gives `groupOf`, neighbouring
 * rows with the same group key sit under a header row that collapses them. The table shows the order it was sent in.
 */
const props = withDefaults(
  defineProps<{
    columns: DataTableColumn[]
    rows: T[]
    meta: TableMeta
    state: TableState
    defaults: TableDefaults & { perPageOptions: readonly number[] }
    /** Where the table's visits go: the page's own URL, without a query. */
    url: string
    rowKey?: string
    /** The name of a row, for the labels of its checkbox; defaults to the row's key. */
    rowLabel?: (row: T) => string
    selectable?: boolean
    searchable?: boolean
    hasActions?: boolean
    searchLabel?: string
    /** The group a row belongs to, for a grouped list: the key tells groups apart, the label is the header's text. */
    groupOf?: (row: T) => { key: string; label: string }
  }>(),
  {
    rowKey: 'id',
    rowLabel: undefined,
    selectable: false,
    searchable: true,
    hasActions: false,
    searchLabel: undefined,
    groupOf: undefined,
  },
)

const emit = defineEmits<{
  'selection-change': [selected: string[]]
}>()

const page = usePage<ShellPageProps>()
const t = computed(() => page.props.translations.console.table)

const busy = ref(false)
const search = ref(props.state.search)
const selected = ref<string[]>([])
// Typed text the server has not seen yet: the input is the user's until the visit that sends it.
let typingPending = false

// The keys of the group runs the user collapsed; kept by key, so a reload that keeps the group keeps it closed.
const collapsed = ref<string[]>([])

// Select-all and the selection cover what is on screen: rows inside a collapsed group are not.
const rowIds = computed(() => visibleRows(runs.value, collapsed.value).map((row) => idOf(row)))
const allSelected = computed(() => selectionState(selected.value, rowIds.value))
const columnCount = computed(() => props.columns.length + (props.selectable ? 1 : 0) + (props.hasActions ? 1 : 0))
const isSearching = computed(() => props.state.search !== '')
const isGrouped = computed(() => props.groupOf !== undefined && (props.state.group ?? '') !== '')

interface BodyRun {
  key: string
  label: string | null
  rows: T[]
}

const runs = computed<BodyRun[]>(() => {
  const groupOf = props.groupOf

  if (!isGrouped.value || !groupOf) {
    return [{ key: '', label: null, rows: props.rows }]
  }

  return groupRuns(props.rows, (row) => groupOf(row).key).map((run) => ({ ...run, label: groupOf(run.rows[0]).label }))
})

function toggleRun(key: string): void {
  collapsed.value = collapsed.value.includes(key) ? collapsed.value.filter((value) => value !== key) : [...collapsed.value, key]
}

function setFilter(key: string, value: string): void {
  const filters = { ...(props.state.filters ?? {}) }

  if (value === '') {
    delete filters[key]
  } else {
    filters[key] = value
  }

  visit({ filters })
}

function setGroup(group: string): void {
  visit({ group })
}

function idOf(row: T): string {
  return String(row[props.rowKey])
}

function labelOf(row: T): string {
  return props.rowLabel ? props.rowLabel(row) : idOf(row)
}

function visit(change: Partial<TableState>, pageNumber = 1): void {
  const next: TableState = { ...props.state, search: search.value, ...change }

  // This visit carries what is in the input now.
  typingPending = false

  router.get(props.url, buildQuery(next, props.defaults, pageNumber), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onStart: () => {
      busy.value = true
    },
    onFinish: () => {
      busy.value = false
    },
  })
}

const visitAfterTyping = useDebounceFn(() => visit({}), 300)

watch(search, (value) => {
  if (value.trim() !== props.state.search) {
    typingPending = true
    void visitAfterTyping()
  }
})

// Back and forward navigation changes the state under the input, but never while the user is typing.
watch(
  () => props.state.search,
  (value) => {
    if (!typingPending && value !== search.value.trim()) {
      search.value = value
    }
  },
)

watch(rowIds, (ids) => {
  const kept = keepVisible(selected.value, ids)

  if (kept.length !== selected.value.length) {
    setSelected(kept)
  }
})

function setSelected(ids: string[]): void {
  selected.value = ids
  emit('selection-change', ids)
}

function clearSearch(): void {
  search.value = ''
  visit({ search: '' })
}

function sortKey(column: DataTableColumn): string {
  return column.sortKey ?? column.key
}

function align(column: DataTableColumn): string {
  return column.align === 'center' ? 'text-center' : column.align === 'right' ? 'text-right' : ''
}

function ariaSort(column: DataTableColumn): 'ascending' | 'descending' | 'none' | undefined {
  if (!column.sortable) {
    return undefined
  }

  const direction = sortDirection(props.state.sort, sortKey(column))

  return direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'
}
</script>

<template>
  <section class="bg-card overflow-hidden rounded-xl border">
    <div v-if="searchable || $slots.toolbar" class="border-border flex flex-wrap items-center gap-2 border-b px-3.5 py-3">
      <div v-if="searchable" class="relative min-w-[200px] flex-1 basis-64 sm:max-w-md">
        <Search class="text-faint-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" aria-hidden="true" />
        <Input
          v-model="search"
          type="search"
          class="bg-surface-muted border-border h-9 px-8 text-[13.5px]"
          :placeholder="t.search_hint"
          :aria-label="searchLabel ?? t.search"
        />
        <Button
          v-if="search !== ''"
          variant="ghost"
          size="icon-xs"
          class="absolute top-1/2 right-2 -translate-y-1/2"
          :aria-label="t.clear_search"
          @click="clearSearch"
        >
          <X aria-hidden="true" />
        </Button>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <slot name="toolbar" :filters="state.filters ?? {}" :set-filter="setFilter" :group="state.group ?? ''" :set-group="setGroup" />
      </div>
    </div>

    <div
      v-if="selectable && selected.length > 0"
      role="status"
      class="bg-primary-soft border-primary-soft-border flex flex-wrap items-center gap-2 border-b px-3.5 py-2 text-[13px]"
    >
      <span class="text-accent-foreground flex-1 font-semibold">{{ interpolate(t.selected, { count: selected.length }) }}</span>
      <div class="flex items-center gap-2">
        <slot name="bulk-actions" :selected="selected" :clear="() => setSelected([])" />
        <Button variant="ghost" size="sm" @click="setSelected([])">{{ t.clear_selection }}</Button>
      </div>
    </div>

    <div>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead v-if="selectable" class="w-11 pl-3.5">
              <Checkbox
                :model-value="allSelected"
                :disabled="rows.length === 0"
                :aria-label="t.select_all"
                @update:model-value="(value) => setSelected(toggleAll(rowIds, value === true))"
              />
            </TableHead>

            <TableHead v-for="column in columns" :key="column.key" :aria-sort="ariaSort(column)" :class="cn(align(column), column.class)">
              <button
                v-if="column.sortable"
                type="button"
                :class="
                  cn(
                    'hover:text-foreground focus-visible:ring-ring/50 -mx-1 inline-flex items-center gap-1 rounded px-1 outline-none focus-visible:ring-3',
                    column.align === 'center' && 'justify-center',
                    column.align === 'right' && 'flex-row-reverse',
                  )
                "
                :aria-label="interpolate(t.sort_by, { column: column.label })"
                @click="visit({ sort: nextSort(state.sort, sortKey(column)) })"
              >
                {{ column.label }}
                <ArrowUp v-if="sortDirection(state.sort, sortKey(column)) === 'asc'" class="size-3.5" aria-hidden="true" />
                <ArrowDown v-else-if="sortDirection(state.sort, sortKey(column)) === 'desc'" class="size-3.5" aria-hidden="true" />
                <ArrowUpDown v-else class="size-3.5 opacity-40" aria-hidden="true" />
              </button>
              <template v-else>{{ column.label }}</template>
            </TableHead>

            <TableHead v-if="hasActions" class="w-px text-right"><span class="sr-only">{{ t.actions }}</span></TableHead>
          </TableRow>
        </TableHeader>

        <TableBody :class="cn('transition-opacity', busy && 'opacity-60')" :aria-busy="busy">
          <TableEmpty v-if="rows.length === 0" :colspan="columnCount">
            <slot name="empty" :searching="isSearching">
              <EmptyState :icon="isSearching ? SearchX : Inbox" :title="isSearching ? t.empty_search : t.empty" />
            </slot>
          </TableEmpty>

          <template v-for="(run, runIndex) in runs" :key="`${runIndex}:${run.key}`">
            <TableRow v-if="run.label !== null" class="bg-surface-muted hover:bg-surface-muted">
              <TableCell :colspan="columnCount" class="py-1.5">
                <button
                  type="button"
                  class="hover:text-foreground focus-visible:ring-ring/50 -mx-1 inline-flex items-center gap-1.5 rounded px-1 text-sm font-medium outline-none focus-visible:ring-3"
                  :aria-expanded="!collapsed.includes(run.key)"
                  :aria-label="interpolate(collapsed.includes(run.key) ? t.expand_group : t.collapse_group, { name: run.label })"
                  @click="toggleRun(run.key)"
                >
                  <ChevronRight v-if="collapsed.includes(run.key)" class="size-4" aria-hidden="true" />
                  <ChevronDown v-else class="size-4" aria-hidden="true" />
                  {{ run.label }}
                  <span class="text-muted-foreground font-normal">({{ run.rows.length }})</span>
                </button>
              </TableCell>
            </TableRow>

            <template v-if="run.label === null || !collapsed.includes(run.key)">
              <TableRow v-for="row in run.rows" :key="idOf(row)" :data-state="selected.includes(idOf(row)) ? 'selected' : undefined">
                <TableCell v-if="selectable" class="w-11 pl-3.5">
                  <Checkbox
                    :model-value="selected.includes(idOf(row))"
                    :aria-label="interpolate(t.select_row, { name: labelOf(row) })"
                    @update:model-value="(value) => setSelected(toggleRow(selected, idOf(row), value === true))"
                  />
                </TableCell>

                <TableCell v-for="column in columns" :key="column.key" :class="cn(align(column), column.class)">
                  <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
                </TableCell>

                <TableCell v-if="hasActions" class="text-right whitespace-nowrap">
                  <slot name="actions" :row="row" />
                </TableCell>
              </TableRow>
            </template>
          </template>
        </TableBody>
      </Table>
    </div>

    <DataTablePagination
      v-if="meta.total > 0"
      :meta="meta"
      :per-page-options="defaults.perPageOptions"
      :labels="t"
      :busy="busy"
      @page="(pageNumber) => visit({}, pageNumber)"
      @per-page="(perPage) => visit({ perPage })"
    />
  </section>
</template>
