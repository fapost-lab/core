<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Eye, SearchX, X } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Label } from '@fapost/ui/components/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import type { LiveChannel } from '@fapost/ui/lib/live-updates'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { useLiveUpdates } from '@fapost/ui/lib/useLiveUpdates'
import { interpolate } from '@fapost/ui/shell'
import CopyButton from '../Contacts/CopyButton.vue'
import LiveHint from '../FlowSessions/LiveHint.vue'
import type { ActivityPeriod } from '../FlowSessions/types'
import { LOG_STATUS_TONES, type FlowLogRow, type FlowLogStatus, type FlowLogsPageProps } from './types'

const props = defineProps<{
  table: { rows: FlowLogRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  /** The period the list covers: the one asked for, or the default. */
  period: ActivityPeriod
  periods: ActivityPeriod[]
  statuses: FlowLogStatus[]
  nodeTypes: string[]
  live: LiveChannel | null
  urls: { index: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'
/** The period the server applies without a filter (FlowActivityPeriod::default()). */
const DEFAULT_PERIOD: ActivityPeriod = '24h'

/** The interval the Filament list polled at. */
const POLL_MS = 60_000

const page = usePage<FlowLogsPageProps>()
const t = computed(() => page.props.translations.console.flow_logs)
const tableLabels = computed(() => page.props.translations.console.table)

const { transport } = useLiveUpdates({ live: () => props.live, only: ['table'], pollMs: POLL_MS })

const columns = computed<DataTableColumn[]>(() => [
  { key: 'createdAt', sortKey: 'created_at', label: t.value.columns.created_at, sortable: true },
  { key: 'status', label: t.value.columns.status },
  { key: 'nodeType', label: t.value.columns.node_type },
  { key: 'nodeId', label: t.value.columns.node_id, class: 'hidden md:table-cell' },
  { key: 'sourceHandle', label: t.value.columns.source_handle, class: 'hidden lg:table-cell' },
  { key: 'hasError', label: t.value.columns.error },
  { key: 'sessionShortId', label: t.value.columns.session, class: 'hidden sm:table-cell' },
])

const sessionFilter = computed(() => props.table.state.filters?.session ?? null)
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
      :row-label="(row) => row.nodeId"
      :selectable="false"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters, setFilter }">
        <Select :model-value="period" @update:model-value="(value) => setFilter('period', value === DEFAULT_PERIOD ? '' : String(value))">
          <SelectTrigger size="sm" class="w-44" :aria-label="t.filters.period">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="option in periods" :key="option" :value="option">{{ t.periods[option] }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="filters.type ?? ALL" @update:model-value="(value) => setFilter('type', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-44" :aria-label="t.filters.type">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.type_all }}</SelectItem>
            <SelectItem v-for="type in nodeTypes" :key="type" :value="type">{{ type }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="filters.status ?? ALL" @update:model-value="(value) => setFilter('status', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.status_all }}</SelectItem>
            <SelectItem v-for="status in statuses" :key="status" :value="status">{{ t.statuses[status] }}</SelectItem>
          </SelectContent>
        </Select>

        <div class="flex items-center gap-2">
          <Switch id="errors-only" :model-value="filters.errors === '1'" @update:model-value="(value) => setFilter('errors', value ? '1' : '')" />
          <Label for="errors-only" class="text-sm font-normal">{{ t.filters.errors }}</Label>
        </div>

        <Button v-if="sessionFilter" variant="outline" size="sm" :aria-label="t.filters.session_clear" :title="t.filters.session_clear" @click="setFilter('session', '')">
          <span class="font-mono">{{ interpolate(t.filters.session, { id: sessionFilter.slice(0, 8) }) }}</span>
          <X aria-hidden="true" />
        </Button>
      </template>

      <template #cell-createdAt="{ row }">
        <Link :href="row.viewUrl" class="whitespace-nowrap hover:underline" :title="row.createdAt">{{ relativeTime(row.createdAt, page.props.locale) }}</Link>
      </template>

      <template #cell-status="{ row }">
        <StatusDot :tone="LOG_STATUS_TONES[row.status] ?? 'neutral'">{{ t.statuses[row.status] ?? row.status }}</StatusDot>
      </template>

      <template #cell-nodeType="{ row }">
        <Badge variant="neutral">{{ row.nodeType }}</Badge>
      </template>

      <template #cell-nodeId="{ row }">
        <span class="block max-w-40 truncate font-mono text-xs" :title="row.nodeId">{{ row.nodeId }}</span>
      </template>

      <template #cell-sourceHandle="{ row }">
        <span v-if="row.sourceHandle" class="font-mono text-xs">{{ row.sourceHandle }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-hasError="{ row }">
        <Badge v-if="row.hasError" variant="danger">{{ t.has_error }}</Badge>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-sessionShortId="{ row }">
        <span class="inline-flex items-center gap-1">
          <Link v-if="row.sessionUrl" :href="row.sessionUrl" class="font-mono text-xs hover:underline" :title="row.sessionId">{{ row.sessionShortId }}</Link>
          <span v-else class="font-mono text-xs" :title="row.sessionId">{{ row.sessionShortId }}</span>
          <CopyButton :value="row.sessionId" />
        </span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end">
          <Button as-child variant="ghost" size="icon-sm">
            <Link :href="row.viewUrl" :aria-label="interpolate(t.open_named, { name: row.nodeId })">
              <Eye aria-hidden="true" />
            </Link>
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>
  </div>
</template>
