<script lang="ts">
import { AppShell } from '@console/shell'

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
import { interpolate } from '@console/shell'
import type { CredentialRow, CredentialsPageProps } from './types'

const props = defineProps<{
  table: { rows: CredentialRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  can: { create: boolean; update: boolean; delete: boolean }
  urls: { index: string; create: string }
}>()

const page = usePage<CredentialsPageProps>()
const t = computed(() => page.props.translations.console.credentials)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'type_label', sortKey: 'type', label: t.value.columns.type, sortable: true, class: 'hidden sm:table-cell' },
  { key: 'allowed_hosts', label: t.value.columns.hosts, class: 'hidden md:table-cell' },
  { key: 'secret_changed_at', label: t.value.columns.secret, sortable: true, class: 'hidden lg:table-cell' },
])

const hasActions = computed(() => props.can.update || props.can.delete)

// The target stays set while the dialog closes, so its text does not change under the closing animation.
const deleteOpen = ref(false)
const rowToDelete = ref<CredentialRow | null>(null)

function askAboutOne(row: CredentialRow): void {
  rowToDelete.value = row
  deleteOpen.value = true
}

function deleteOne(): void {
  if (rowToDelete.value) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
}

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(page.props.locale, { dateStyle: 'medium' }).format(new Date(iso))
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

      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
      </template>

      <template #cell-type_label="{ row }">
        <Badge variant="neutral">{{ row.type_label }}</Badge>
      </template>

      <template #cell-allowed_hosts="{ row }">
        <div class="flex flex-wrap gap-1">
          <Badge v-for="host in row.allowed_hosts.slice(0, 2)" :key="host" variant="neutral">{{ host }}</Badge>
          <Badge v-if="row.allowed_hosts.length > 2" variant="neutral" :title="row.allowed_hosts.join(', ')">+{{ row.allowed_hosts.length - 2 }}</Badge>
        </div>
      </template>

      <template #cell-secret_changed_at="{ row }">
        <span class="text-muted-foreground" :title="row.secret_changed_at">{{ interpolate(t.secret_set, { date: formatDate(row.secret_changed_at) }) }}</span>
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
      v-model:open="deleteOpen"
      :title="t.delete_one.title"
      :description="interpolate(t.delete_one.description, { name: rowToDelete?.name ?? '' })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="deleteOne"
    />
  </div>
</template>
