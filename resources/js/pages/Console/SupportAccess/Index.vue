<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { SearchX } from '@lucide/vue'
import { StatusDot } from '@fapost/ui/components/badge'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { formatDateTime } from './datetime'
import type { SupportAccessPageProps, SupportAccessRow } from './types'

defineProps<{
  table: { rows: SupportAccessRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  urls: { index: string }
}>()

const page = usePage<SupportAccessPageProps>()
const t = computed(() => page.props.translations.console.support_access)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'operatorName', sortKey: 'operator_name', label: t.value.columns.operator, sortable: true },
  { key: 'operatorEmail', label: t.value.columns.email, class: 'hidden md:table-cell' },
  { key: 'ip', label: t.value.columns.ip, class: 'hidden lg:table-cell' },
  { key: 'enteredAt', sortKey: 'entered_at', label: t.value.columns.entered_at, sortable: true },
  { key: 'leftAt', sortKey: 'left_at', label: t.value.columns.left_at, sortable: true, class: 'hidden sm:table-cell' },
])
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground">{{ t.description }}</p>
    </div>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      :row-label="(row) => row.operatorName"
      :search-label="t.search_label"
    >
      <template #cell-operatorName="{ row }">
        <div class="flex flex-col">
          <span class="font-medium">{{ row.operatorName }}</span>
          <span class="text-muted-foreground text-sm md:hidden">{{ row.operatorEmail }}</span>
        </div>
      </template>

      <template #cell-ip="{ row }">
        <span v-if="row.ip" class="font-mono text-sm">{{ row.ip }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-enteredAt="{ row }">
        <time :datetime="row.enteredAt">{{ formatDateTime(row.enteredAt, page.props.locale) }}</time>
      </template>

      <template #cell-leftAt="{ row }">
        <StatusDot v-if="row.isOpen" tone="success">{{ t.in_progress }}</StatusDot>
        <time v-else-if="row.leftAt" :datetime="row.leftAt">{{ formatDateTime(row.leftAt, page.props.locale) }}</time>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>
  </div>
</template>
