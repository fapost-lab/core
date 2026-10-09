<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { CircleCheck, CircleX, EllipsisVertical, ExternalLink, KeyRound, Pencil, Plus, Trash2 } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import type { ChannelRow, ChannelsPageProps } from './types'

const props = defineProps<{
  table: { rows: ChannelRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  limit: { reached: boolean; hint: string | null }
  can: { create: boolean; update: boolean; delete: boolean; rotate: boolean }
  urls: { index: string; create: string }
}>()

const page = usePage<ChannelsPageProps>()
const t = computed(() => page.props.translations.console.channels)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'type', label: t.value.columns.type, sortable: true },
  { key: 'handle', label: t.value.columns.bot },
  { key: 'isActive', sortKey: 'is_active', label: t.value.columns.active, sortable: true, align: 'center' },
  { key: 'updatedAt', sortKey: 'updated_at', label: t.value.columns.updated, sortable: true, class: 'hidden sm:table-cell' },
])

const hasMenu = computed(() => props.can.update || props.can.rotate || props.can.delete)

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const rotateOpen = ref(false)
const deleteOpen = ref(false)
const rowToRotate = ref<ChannelRow | null>(null)
const rowToDelete = ref<ChannelRow | null>(null)

function name(row: ChannelRow | null): string {
  return row ? (row.handle ?? row.typeLabel) : ''
}

function askAboutRotation(row: ChannelRow): void {
  rowToRotate.value = row
  rotateOpen.value = true
}

function askAboutDeletion(row: ChannelRow): void {
  rowToDelete.value = row
  deleteOpen.value = true
}

function rotate(): void {
  if (rowToRotate.value) {
    router.post(rowToRotate.value.rotateUrl, {}, { preserveScroll: true })
  }
}

function destroy(): void {
  if (rowToDelete.value) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
}
</script>

<template>
  <Head :title="t.title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-2xl font-semibold tracking-wide uppercase">{{ t.title }}</h1>
      <p class="text-muted-foreground text-sm">{{ t.description }}</p>
      <p v-if="limit.hint" class="text-destructive text-sm font-medium" role="status">{{ limit.hint }}</p>
    </div>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      :row-label="(row) => name(row)"
      :searchable="false"
      :has-actions="hasMenu"
    >
      <template #toolbar>
        <Button v-if="can.create" as-child class="ml-auto">
          <Link :href="urls.create">
            <Plus aria-hidden="true" />
            {{ t.new }}
          </Link>
        </Button>
      </template>

      <template #cell-type="{ row }">
        <Badge variant="secondary">{{ row.typeLabel }}</Badge>
      </template>

      <template #cell-handle="{ row }">
        <a v-if="row.url" :href="row.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 underline-offset-4 hover:underline">
          {{ row.handle }}
          <ExternalLink class="size-3.5" aria-hidden="true" />
        </a>
        <span v-else class="text-muted-foreground">{{ t.bot_pending }}</span>
      </template>

      <template #cell-isActive="{ row }">
        <CircleCheck v-if="row.isActive" class="mx-auto size-5 text-green-600 dark:text-green-500" role="img" :aria-label="t.status.active" />
        <CircleX v-else class="text-muted-foreground mx-auto size-5" role="img" :aria-label="t.status.inactive" />
      </template>

      <template #cell-updatedAt="{ row }">
        <span v-if="row.updatedAt" class="text-muted-foreground" :title="row.updatedAt">{{ relativeTime(row.updatedAt, page.props.locale) }}</span>
      </template>

      <template #actions="{ row }">
        <DropdownMenu v-if="hasMenu">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: name(row) })">
              <EllipsisVertical aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem v-if="can.update" as-child>
              <Link :href="row.editUrl">
                <Pencil aria-hidden="true" />
                {{ common.edit }}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem v-if="can.rotate" variant="destructive" @select="askAboutRotation(row)">
              <KeyRound aria-hidden="true" />
              {{ t.rotate }}
            </DropdownMenuItem>
            <template v-if="can.delete">
              <DropdownMenuSeparator v-if="can.update || can.rotate" />
              <DropdownMenuItem variant="destructive" @select="askAboutDeletion(row)">
                <Trash2 aria-hidden="true" />
                {{ common.delete }}
              </DropdownMenuItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenu>
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
      v-model:open="rotateOpen"
      destructive
      :title="t.rotate_dialog.title"
      :description="interpolate(t.rotate_dialog.description, { name: name(rowToRotate) })"
      :confirm-label="t.rotate"
      :cancel-label="common.cancel"
      @confirm="rotate"
    />

    <ConfirmDialog
      v-model:open="deleteOpen"
      destructive
      :title="t.delete_one.title"
      :description="interpolate(t.delete_one.description, { name: name(rowToDelete) })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="destroy"
    />
  </div>
</template>
