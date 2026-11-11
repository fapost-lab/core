<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { EllipsisVertical, Plus, SearchX, SquarePen, Trash2 } from '@lucide/vue'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { interpolate } from '@console/shell'
import type { FlowTemplateRow, FlowTemplatesPageProps } from './types'

const props = defineProps<{
  table: { rows: FlowTemplateRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  limit: { reached: boolean; hint: string | null }
  can: { create: boolean; delete: boolean }
  urls: { index: string; create: string; destroyMany: string }
}>()

const page = usePage<FlowTemplatesPageProps>()
const t = computed(() => page.props.translations.console.flow_templates)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'publishedVersion', label: t.value.columns.version, class: 'hidden sm:table-cell' },
  { key: 'usedBy', label: t.value.columns.used_by, class: 'hidden sm:table-cell' },
])

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const deleteOneOpen = ref(false)
const deleteManyOpen = ref(false)
const rowToDelete = ref<FlowTemplateRow | null>(null)
const idsToDelete = ref<string[]>([])

function askAboutOne(row: FlowTemplateRow): void {
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
      <p v-if="limit.hint" class="text-destructive text-sm font-medium" role="status">{{ limit.hint }}</p>
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
      has-actions
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
        <Button variant="outline-danger" size="sm" @click="askAboutMany(selected)">
          <Trash2 aria-hidden="true" />
          {{ t.delete_selected }}
        </Button>
      </template>

      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
      </template>

      <template #cell-publishedVersion="{ row }">
        <span v-if="row.publishedVersion !== null">v{{ row.publishedVersion }}</span>
        <span v-else class="text-muted-foreground" :title="t.no_version">—</span>
      </template>

      <template #cell-usedBy="{ row }">
        <span v-if="row.usedBy > 0">{{ row.usedBy }}</span>
        <span v-else class="text-muted-foreground">{{ t.not_used }}</span>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-1">
          <Button as-child variant="outline" size="sm">
            <a :href="row.builderUrl" :aria-label="interpolate(t.builder_named, { name: row.name })">
              <SquarePen aria-hidden="true" />
              {{ t.builder }}
            </a>
          </Button>

          <DropdownMenu v-if="can.delete">
            <DropdownMenuTrigger as-child>
              <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: row.name })">
                <EllipsisVertical aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem variant="destructive" @select="askAboutOne(row)">
                <Trash2 aria-hidden="true" />
                {{ common.delete }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
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
