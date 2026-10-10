<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { EllipsisVertical, ExternalLink, KeyRound, Pencil, RefreshCw, SearchX, Trash2 } from '@lucide/vue'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import type { ChannelRow, ChannelsPageProps } from './types'
import WebhookStatus from './WebhookStatus.vue'

/**
 * A channel list with its row actions (edit, register the webhook again, rotate the hash, delete), shared by the
 * assistant's channels screen and the assistant's page in the admin panel. Each row carries its own URLs, so the list
 * does not know which screen it is on; `url` is the page the list's sort and paging stay on.
 */
const props = defineProps<{
  table: { rows: ChannelRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  can: { update: boolean; delete: boolean; rotate: boolean }
  url: string
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

function registerAgain(row: ChannelRow): void {
  router.post(row.registerWebhookUrl, {}, { preserveScroll: true })
}

function destroy(): void {
  if (rowToDelete.value) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
}
</script>

<template>
  <div class="flex w-full flex-col gap-5">
  <DataTable
    :columns="columns"
    :rows="table.rows"
    :meta="table.meta"
    :state="table.state"
    :defaults="table.defaults"
    :url="url"
    :row-label="(row) => name(row)"
    :searchable="false"
    :has-actions="hasMenu"
  >
    <template #toolbar>
      <slot name="toolbar" />
    </template>

    <template #cell-type="{ row }">
      <Badge variant="info">{{ row.typeLabel }}</Badge>
    </template>

    <template #cell-handle="{ row }">
      <a v-if="row.url" :href="row.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 underline-offset-4 hover:underline">
        {{ row.handle }}
        <ExternalLink class="size-3.5" aria-hidden="true" />
      </a>
      <span v-else class="text-muted-foreground">{{ t.bot_pending }}</span>
      <WebhookStatus v-if="row.webhook === 'failed'" class="ml-2" />
    </template>

    <template #cell-isActive="{ row }">
      <StatusDot :tone="row.isActive ? 'success' : 'neutral'">{{ row.isActive ? t.status.active : t.status.inactive }}</StatusDot>
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
          <DropdownMenuItem v-if="can.update && row.webhook === 'failed'" @select="registerAgain(row)">
            <RefreshCw aria-hidden="true" />
            {{ t.webhook.register_again }}
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
      <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
      <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
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
