import { computed, type ComputedRef } from 'vue'
import type { DataTableColumn } from '@fapost/ui/components/data-table'
import type { ColumnExtension, ColumnTarget } from './extensions'
import { useExtensions } from './useExtensions'

/** A Solution's column as the kit's DataTable takes it: keyed by its id, which is also the key of its row value. */
export function toTableColumn(column: ColumnExtension): DataTableColumn {
  return {
    key: column.id,
    label: column.label,
    ...(column.cell === 'number' ? { align: 'right' as const } : {}),
    class: 'hidden md:table-cell',
  }
}

/**
 * The columns Solutions add to a Core table, after Core's own: `columns` for the DataTable, `extra` for the
 * `cell-<id>` slots that draw them by cell type.
 */
export function useSolutionColumns(
  target: ColumnTarget,
  core: ComputedRef<DataTableColumn[]>,
): { columns: ComputedRef<DataTableColumn[]>; extra: ComputedRef<ColumnExtension[]> } {
  const { items } = useExtensions('columns', target)

  return {
    columns: computed(() => [...core.value, ...items.value.map(toTableColumn)]),
    extra: items,
  }
}
