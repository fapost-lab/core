<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3'
import { Ban, EllipsisVertical, Pencil, Plus, SearchX, Send, Trash2 } from '@lucide/vue'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import SendDialog from './SendDialog.vue'
import type { BroadcastRow, BroadcastStatus, BroadcastsPageProps, SelectOption } from './types'

const props = defineProps<{
  table: { rows: BroadcastRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  statuses: BroadcastStatus[]
  segments: SelectOption[]
  can: { create: boolean; update: boolean; delete: boolean; send: boolean; cancel: boolean }
  urls: { index: string; create: string; reach: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'

/** How often the list asks for fresh numbers while a broadcast is running, and for how long the page keeps asking. */
const POLL_MS = 5000
const POLL_LIMIT_MS = 60 * 60 * 1000

const TONES = { draft: 'neutral', running: 'info', completed: 'success', cancelled: 'warning', failed: 'danger' } as const

const page = usePage<BroadcastsPageProps>()
const t = computed(() => page.props.translations.console.broadcasts)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name },
  { key: 'target', label: t.value.columns.target, class: 'hidden md:table-cell' },
  { key: 'status', label: t.value.columns.status },
  { key: 'progress', label: t.value.columns.progress },
  { key: 'failed', label: t.value.columns.failed, class: 'hidden lg:table-cell' },
  { key: 'skipped', label: t.value.columns.skipped, class: 'hidden lg:table-cell' },
  { key: 'createdAt', sortKey: 'created_at', label: t.value.columns.created, sortable: true, class: 'hidden sm:table-cell' },
])

function hasMenu(row: BroadcastRow): boolean {
  return Boolean(row.editUrl || row.sendUrl || row.cancelUrl || row.deleteUrl)
}

// Polling runs only while the page shows a running broadcast, and gives up after an hour on the page.
const poll = usePoll(POLL_MS, { only: ['table'] }, { autoStart: false })
const hasRunning = computed(() => props.table.rows.some((row) => row.status === 'running'))
let pollDeadline: ReturnType<typeof setTimeout> | null = null
let pollExpired = false

function stopPolling(): void {
  poll.stop()
}

watch(
  hasRunning,
  (running) => {
    if (running && !pollExpired) {
      poll.start()
      pollDeadline ??= setTimeout(() => {
        pollExpired = true
        stopPolling()
      }, POLL_LIMIT_MS)
    } else {
      stopPolling()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (pollDeadline !== null) {
    clearTimeout(pollDeadline)
  }
})

// The rows the dialogs were opened for are kept as they were then: a draft is sent on the revision it showed, and the
// text of a closing dialog does not change under its animation.
const sendOpen = ref(false)
const cancelOpen = ref(false)
const deleteOpen = ref(false)
const rowToSend = ref<BroadcastRow | null>(null)
const rowToCancel = ref<BroadcastRow | null>(null)
const rowToDelete = ref<BroadcastRow | null>(null)
const sending = ref(false)

function askToSend(row: BroadcastRow): void {
  rowToSend.value = { ...row }
  sendOpen.value = true
}

function askToCancel(row: BroadcastRow): void {
  rowToCancel.value = row
  cancelOpen.value = true
}

function askToDelete(row: BroadcastRow): void {
  rowToDelete.value = row
  deleteOpen.value = true
}

// One send at a time: the button is off while the request is on its way, and this returns early if it is clicked anyway.
function send(): void {
  const row = rowToSend.value

  if (sending.value || !row?.sendUrl || !row.revision) {
    return
  }

  router.post(
    row.sendUrl,
    { revision: row.revision },
    {
      preserveScroll: true,
      onStart: () => {
        sending.value = true
      },
      onFinish: () => {
        sending.value = false
      },
    },
  )
}

function cancelRun(): void {
  if (rowToCancel.value?.cancelUrl) {
    router.post(rowToCancel.value.cancelUrl, {}, { preserveScroll: true })
  }
}

function deleteOne(): void {
  if (rowToDelete.value?.deleteUrl) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
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
      :selectable="false"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters, setFilter }">
        <Select :model-value="filters.status ?? ALL" @update:model-value="(value) => setFilter('status', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-44" :aria-label="t.filters.status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.status_all }}</SelectItem>
            <SelectItem v-for="status in statuses" :key="status" :value="status">{{ t.statuses[status] }}</SelectItem>
          </SelectContent>
        </Select>

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

      <template #cell-target="{ row }">
        <Badge variant="neutral">{{ t.targets[row.target] }}</Badge>
      </template>

      <template #cell-status="{ row }">
        <StatusDot :tone="TONES[row.status]">{{ t.statuses[row.status] }}</StatusDot>
      </template>

      <template #cell-progress="{ row }">
        <span v-if="row.status === 'draft'" class="text-muted-foreground">—</span>
        <span v-else-if="row.status === 'running' && row.total === 0" class="text-muted-foreground">{{ t.preparing }}</span>
        <span v-else class="tabular-nums">{{ row.sent }} / {{ row.total }}</span>
      </template>

      <template #cell-failed="{ row }">
        <Badge v-if="row.failed > 0" variant="danger" class="tabular-nums">{{ row.failed }}</Badge>
        <span v-else class="text-muted-foreground tabular-nums">{{ row.status === 'draft' ? '—' : 0 }}</span>
      </template>

      <template #cell-skipped="{ row }">
        <Badge v-if="row.skipped > 0" variant="neutral" class="tabular-nums">{{ row.skipped }}</Badge>
        <span v-else class="text-muted-foreground tabular-nums">{{ row.status === 'draft' ? '—' : 0 }}</span>
      </template>

      <template #cell-createdAt="{ row }">
        <span v-if="row.createdAt" class="text-muted-foreground" :title="row.createdAt">{{ relativeTime(row.createdAt, page.props.locale) }}</span>
      </template>

      <template #actions="{ row }">
        <DropdownMenu v-if="hasMenu(row)">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: row.name })">
              <EllipsisVertical aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem v-if="row.editUrl" as-child>
              <Link :href="row.editUrl">
                <Pencil aria-hidden="true" />
                {{ t.actions.edit }}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem v-if="row.sendUrl" @select="askToSend(row)">
              <Send aria-hidden="true" />
              {{ t.actions.send }}
            </DropdownMenuItem>
            <DropdownMenuItem v-if="row.cancelUrl" variant="destructive" @select="askToCancel(row)">
              <Ban aria-hidden="true" />
              {{ t.actions.cancel }}
            </DropdownMenuItem>
            <template v-if="row.deleteUrl">
              <DropdownMenuSeparator v-if="row.editUrl || row.sendUrl || row.cancelUrl" />
              <DropdownMenuItem variant="destructive" @select="askToDelete(row)">
                <Trash2 aria-hidden="true" />
                {{ t.actions.delete }}
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

    <SendDialog v-model:open="sendOpen" :row="rowToSend" :segments="segments" :reach-url="urls.reach" :sending="sending" @confirm="send" />

    <ConfirmDialog
      v-model:open="cancelOpen"
      :title="t.cancel.title"
      :description="interpolate(t.cancel.description, { name: rowToCancel?.name ?? '' })"
      :confirm-label="t.cancel.confirm"
      :cancel-label="t.cancel.keep"
      @confirm="cancelRun"
    />

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t.delete.title"
      :description="interpolate(t.delete.description, { name: rowToDelete?.name ?? '' })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="deleteOne"
    />
  </div>
</template>
