<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { Pencil, Plus, RefreshCw, Trash2 } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import type { ContactSegmentRow, ContactSegmentsPageProps } from './types'

defineProps<{
  table: { rows: ContactSegmentRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  can: { create: boolean; update: boolean; delete: boolean }
  urls: { index: string; create: string }
}>()

/** How a condition count is drawn. One place, so the badge look follows the kit when it changes. */
const CONDITIONS_BADGE = 'secondary' as const

const page = usePage<ContactSegmentsPageProps>()
const t = computed(() => page.props.translations.console.contact_segments)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'conditionsCount', label: t.value.columns.conditions, align: 'center', class: 'hidden sm:table-cell' },
  { key: 'size', sortKey: 'cached_count', label: t.value.columns.size, sortable: true, align: 'right' },
  { key: 'countedAt', sortKey: 'cached_count_at', label: t.value.columns.counted_at, sortable: true, class: 'hidden md:table-cell' },
])

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const deleteOpen = ref(false)
const rowToDelete = ref<ContactSegmentRow | null>(null)
const recounting = ref<string | null>(null)

function askAboutDelete(row: ContactSegmentRow): void {
  rowToDelete.value = row
  deleteOpen.value = true
}

function deleteOne(): void {
  if (rowToDelete.value) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
}

function recount(row: ContactSegmentRow): void {
  router.post(row.countUrl, {}, {
    preserveScroll: true,
    onStart: () => {
      recounting.value = row.id
    },
    onFinish: () => {
      recounting.value = null
    },
  })
}
</script>

<template>
  <Head :title="t.title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-2xl font-semibold tracking-wide uppercase">{{ t.title }}</h1>
      <p class="text-muted-foreground text-sm">{{ t.description }}</p>
      <p class="text-muted-foreground text-sm">{{ t.size_note }}</p>
    </div>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      :row-label="(row) => row.name"
      :has-actions="can.update || can.delete"
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

      <template #cell-conditionsCount="{ row }">
        <span class="inline-flex items-center gap-2">
          <Badge :variant="CONDITIONS_BADGE">{{ row.conditionsCount }}</Badge>
          <span v-if="row.conditionsCount > 1" class="text-muted-foreground text-xs">{{ t.match_short[row.match] ?? row.match }}</span>
        </span>
      </template>

      <template #cell-size="{ row }">
        <span v-if="row.size !== null" class="tabular-nums">{{ row.size }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-countedAt="{ row }">
        <span v-if="row.countedAt" class="text-muted-foreground" :title="row.countedAt">{{ relativeTime(row.countedAt, page.props.locale) }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button
            v-if="can.update"
            variant="ghost"
            size="icon-sm"
            :disabled="recounting === row.id"
            :aria-label="interpolate(t.recount_named, { name: row.name })"
            @click="recount(row)"
          >
            <RefreshCw :class="{ 'animate-spin': recounting === row.id }" aria-hidden="true" />
          </Button>
          <Button v-if="can.update" as-child variant="ghost" size="icon-sm">
            <Link :href="row.editUrl" :aria-label="interpolate(common.edit_named, { name: row.name })">
              <Pencil aria-hidden="true" />
            </Link>
          </Button>
          <Button v-if="can.delete" variant="ghost" size="icon-sm" :aria-label="interpolate(common.delete_named, { name: row.name })" @click="askAboutDelete(row)">
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <p v-if="searching" class="text-muted-foreground">{{ tableLabels.empty_search }}</p>
        <div v-else class="flex flex-col items-center gap-1 text-center">
          <p class="font-medium">{{ t.empty }}</p>
          <p class="text-muted-foreground text-sm">{{ t.empty_hint }}</p>
        </div>
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
