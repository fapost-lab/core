<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Pencil, RotateCcw, SearchX } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { interpolate } from '@fapost/ui/shell'
import EditDialog from './EditDialog.vue'
import { cellOf, statusVariant, truncate } from './translations'
import type { TranslationRow, TranslationsPageProps } from './types'

/**
 * The translations page of both panels: the system catalog's keys, a column per language of the workspace. On an
 * assistant (`layered`) a cell can also show the workspace's override, marked as inherited.
 */
const props = defineProps<{
  table: { rows: TranslationRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  languages: string[]
  groups: string[]
  layered: boolean
  urls: { index: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'

const page = usePage<TranslationsPageProps>()
const t = computed(() => page.props.translations.console.translations)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'group', label: t.value.columns.group, sortable: true, class: 'hidden md:table-cell' },
  { key: 'key', label: t.value.columns.key, sortable: true },
  { key: 'description', label: t.value.columns.description, class: 'hidden lg:table-cell' },
  ...props.languages.map((language) => ({ key: `language_${language}`, label: language.toUpperCase() })),
])

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const editOpen = ref(false)
const resetOpen = ref(false)
const rowToEdit = ref<TranslationRow | null>(null)
const rowToReset = ref<TranslationRow | null>(null)

function edit(row: TranslationRow): void {
  rowToEdit.value = row
  editOpen.value = true
}

function askReset(row: TranslationRow): void {
  rowToReset.value = row
  resetOpen.value = true
}

function reset(): void {
  if (rowToReset.value) {
    router.delete(rowToReset.value.resetUrl, { preserveScroll: true })
  }
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground">{{ layered ? t.description_layered : t.description }}</p>
      <ul class="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
        <li class="inline-flex items-center gap-1.5"><Badge variant="warning">{{ t.status.override }}</Badge>{{ t.legend.override }}</li>
        <li v-if="layered" class="inline-flex items-center gap-1.5"><Badge variant="info">{{ t.status.inherited }}</Badge>{{ t.legend.inherited }}</li>
        <li>{{ t.legend.default }}</li>
      </ul>
    </div>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      row-key="key"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters, setFilter }">
        <Select :model-value="filters.group ?? ALL" @update:model-value="(value) => setFilter('group', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-44" :aria-label="t.filters.group">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.group_all }}</SelectItem>
            <SelectItem v-for="group in groups" :key="group" :value="group">{{ group }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #cell-group="{ row }">
        <Badge variant="neutral">{{ row.group }}</Badge>
      </template>

      <template #cell-key="{ row }">
        <span class="font-mono text-xs break-all">{{ row.key }}</span>
      </template>

      <template #cell-description="{ row }">
        <span class="text-muted-foreground text-xs" :title="row.description">{{ truncate(row.description, 80) }}</span>
      </template>

      <template v-for="language in languages" #[`cell-language_${language}`]="{ row }">
        <template v-if="cellOf(row, language)">
          <span class="flex max-w-56 flex-col items-start gap-1">
            <span class="text-sm" :title="cellOf(row, language)!.value">{{ truncate(cellOf(row, language)!.value) || '—' }}</span>
            <Badge v-if="statusVariant(cellOf(row, language)!.status)" :variant="statusVariant(cellOf(row, language)!.status)!">
              {{ t.status[cellOf(row, language)!.status] }}
            </Badge>
          </span>
        </template>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.edit_named, { name: row.key })" @click="edit(row)">
            <Pencil aria-hidden="true" />
          </Button>
          <Button v-if="row.hasOverride" variant="ghost" size="icon-sm" :aria-label="interpolate(t.reset_named, { name: row.key })" @click="askReset(row)">
            <RotateCcw aria-hidden="true" />
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" />
      </template>
    </DataTable>

    <EditDialog v-model:open="editOpen" :row="rowToEdit" />

    <ConfirmDialog
      v-model:open="resetOpen"
      :title="t.reset.title"
      :description="interpolate(t.reset.description, { name: rowToReset?.key ?? '' })"
      :confirm-label="t.reset.confirm"
      :cancel-label="page.props.translations.console.form.cancel"
      @confirm="reset"
    />
  </div>
</template>
