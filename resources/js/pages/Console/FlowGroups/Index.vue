<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { Pencil, Plus, SearchX, Trash2 } from '@lucide/vue'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { interpolate } from '@fapost/ui/shell'
import type { FlowGroupRow, FlowGroupsPageProps } from './types'

const props = defineProps<{
  table: { rows: FlowGroupRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  can: { create: boolean; update: boolean; delete: boolean }
  urls: { index: string; create: string; destroyMany: string }
}>()

const page = usePage<FlowGroupsPageProps>()
const t = computed(() => page.props.translations.console.flow_groups)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'flowsCount', sortKey: 'drafts_count', label: t.value.columns.flows, sortable: true, align: 'center' },
])

const hasActions = computed(() => props.can.update || props.can.delete)

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const deleteOneOpen = ref(false)
const deleteManyOpen = ref(false)
const rowToDelete = ref<FlowGroupRow | null>(null)
const idsToDelete = ref<string[]>([])

function askAboutOne(row: FlowGroupRow): void {
  rowToDelete.value = row
  deleteOneOpen.value = true
}

function askAboutMany(selected: string[]): void {
  idsToDelete.value = [...selected]
  deleteManyOpen.value = true
}

function deleteOne(): void {
  if (rowToDelete.value) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
}

function deleteMany(): void {
  router.delete(props.urls.destroyMany, { data: { ids: idsToDelete.value }, preserveScroll: true })
}
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
      :row-label="(row) => row.name"
      :selectable="can.delete"
      :has-actions="hasActions"
      :search-label="t.search_label"
    >
      <template #toolbar>
        <Button v-if="can.create" as-child>
          <Link :href="urls.create">
            <Plus aria-hidden="true" />
            {{ t.new }}
          </Link>
        </Button>
      </template>

      <template #bulk-actions="{ selected }">
        <Button variant="destructive" size="sm" @click="askAboutMany(selected)">
          <Trash2 aria-hidden="true" />
          {{ t.delete_selected }}
        </Button>
      </template>

      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
      </template>

      <template #cell-flowsCount="{ row }">
        <Badge variant="neutral">{{ row.flowsCount }}</Badge>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button v-if="can.update" as-child variant="ghost" size="icon-sm">
            <Link :href="row.editUrl" :aria-label="interpolate(common.edit_named, { name: row.name })">
              <Pencil aria-hidden="true" />
            </Link>
          </Button>
          <Button v-if="can.delete" variant="ghost" size="icon-sm" :aria-label="interpolate(common.delete_named, { name: row.name })" @click="askAboutOne(row)">
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>

    <ConfirmDialog
      v-model:open="deleteOneOpen"
      :title="t.delete_one.title"
      :description="interpolate(t.delete_one.description, { name: rowToDelete?.name ?? '' })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="deleteOne"
    />

    <ConfirmDialog
      v-model:open="deleteManyOpen"
      :title="t.delete_many.title"
      :description="interpolate(t.delete_many.description, { count: idsToDelete.length })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="deleteMany"
    />
  </div>
</template>
