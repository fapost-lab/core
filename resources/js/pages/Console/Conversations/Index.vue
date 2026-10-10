<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Eye, SearchX, UserRound } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import type { LiveChannel } from '@fapost/ui/lib/live-updates'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { useLiveUpdates } from '@fapost/ui/lib/useLiveUpdates'
import { interpolate } from '@fapost/ui/shell'
import LiveHint from './LiveHint.vue'
import { STATUS_TONES, type ConversationRow, type ConversationStatus, type ConversationsPageProps } from './types'

const props = defineProps<{
  table: { rows: ConversationRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  statuses: ConversationStatus[]
  live: LiveChannel | null
  urls: { index: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ANY = '__any'

const POLL_MS = 30_000

const page = usePage<ConversationsPageProps>()
const t = computed(() => page.props.translations.console.conversations)
const tableLabels = computed(() => page.props.translations.console.table)

const { transport } = useLiveUpdates({ live: () => props.live, only: ['table', 'navigation'], pollMs: POLL_MS })

const columns = computed<DataTableColumn[]>(() => [
  { key: 'contact', label: t.value.columns.contact },
  { key: 'platform', label: t.value.columns.platform, class: 'hidden sm:table-cell' },
  { key: 'preview', label: t.value.columns.last_message, class: 'hidden md:table-cell' },
  { key: 'unread', label: t.value.columns.unread },
  { key: 'messages', label: t.value.columns.messages, class: 'hidden lg:table-cell text-right' },
  { key: 'status', label: t.value.columns.status },
  { key: 'lastMessageAt', sortKey: 'last_message_at', label: t.value.columns.last_activity, sortable: true, class: 'hidden sm:table-cell' },
])

function capitalise(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1)
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div class="flex flex-col gap-1">
        <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
        <p class="text-muted-foreground">{{ t.description }}</p>
      </div>
      <LiveHint :transport="transport" :poll-ms="POLL_MS" :labels="t.live" />
    </div>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      :row-label="(row) => row.contact"
      :selectable="false"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters, setFilter }">
        <Select :model-value="filters.status ?? ANY" @update:model-value="(value) => setFilter('status', value === ANY ? '' : String(value))">
          <SelectTrigger size="sm" class="w-48" :aria-label="t.filters.status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ANY">{{ t.filters.status_all }}</SelectItem>
            <SelectItem v-for="status in statuses" :key="status" :value="status">{{ t.statuses[status] }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #cell-contact="{ row }">
        <span class="flex flex-wrap items-center gap-2">
          <Link :href="row.viewUrl" :class="['block max-w-48 truncate hover:underline', row.unread > 0 ? 'font-semibold' : 'font-medium']" :title="row.contact">{{ row.contact }}</Link>
          <Badge v-if="row.owner === 'staff'" variant="info"><UserRound aria-hidden="true" />{{ t.owner.staff }}</Badge>
        </span>
      </template>

      <template #cell-platform="{ row }">
        <Badge variant="neutral">{{ capitalise(row.platform) }}</Badge>
      </template>

      <template #cell-preview="{ row }">
        <span v-if="row.preview" class="text-muted-foreground block max-w-72 truncate" :title="row.preview">{{ row.preview }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-unread="{ row }">
        <Badge v-if="row.unread > 0" variant="danger">{{ row.unread }}</Badge>
      </template>

      <template #cell-messages="{ row }">
        <span class="block text-right tabular-nums">{{ row.messages }}</span>
      </template>

      <template #cell-status="{ row }">
        <StatusDot :tone="STATUS_TONES[row.status]">{{ t.statuses[row.status] }}</StatusDot>
      </template>

      <template #cell-lastMessageAt="{ row }">
        <span v-if="row.lastMessageAt" class="text-muted-foreground" :title="row.lastMessageAt">{{ relativeTime(row.lastMessageAt, page.props.locale) }}</span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end">
          <Button as-child variant="ghost" size="icon-sm">
            <Link :href="row.viewUrl" :aria-label="interpolate(t.open_named, { name: row.contact })">
              <Eye aria-hidden="true" />
            </Link>
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" />
      </template>
    </DataTable>
  </div>
</template>
