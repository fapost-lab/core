<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { Pencil, Plus, SearchX, Trash2 } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@fapost/ui/shell'
import type { RoleRow, RolesPageProps } from './types'

const props = defineProps<{
  table: { rows: RoleRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  can: { create: boolean; update: boolean; delete: boolean }
  urls: { index: string; create: string; destroyMany: string }
}>()

const page = usePage<RolesPageProps>()
const t = computed(() => page.props.translations.console.roles)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'displayName', label: t.value.columns.display_name, class: 'hidden md:table-cell' },
  { key: 'isSystem', label: t.value.columns.system },
  { key: 'permissionsCount', sortKey: 'permissions_count', label: t.value.columns.permissions, sortable: true, align: 'center' },
])

const deleteManyOpen = ref(false)
const idsToDelete = ref<string[]>([])

function askAboutMany(selected: string[]): void {
  idsToDelete.value = [...selected]
  deleteManyOpen.value = true
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
      :row-label="(row) => row.displayName ?? row.name"
      :selectable="can.delete"
      :has-actions="can.update"
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
        <span class="font-mono text-[13px] font-medium">{{ row.name }}</span>
      </template>

      <template #cell-displayName="{ row }">
        <span v-if="row.displayName">{{ row.displayName }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-isSystem="{ row }">
        <Badge :variant="row.isSystem ? 'info' : 'neutral'">{{ row.isSystem ? t.system : t.custom }}</Badge>
      </template>

      <template #cell-permissionsCount="{ row }">
        <Badge variant="neutral">{{ row.permissionsCount }}</Badge>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button as-child variant="ghost" size="icon-sm">
            <Link :href="row.editUrl" :aria-label="interpolate(common.edit_named, { name: row.displayName ?? row.name })">
              <Pencil aria-hidden="true" />
            </Link>
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>

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
