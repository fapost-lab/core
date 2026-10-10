<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Eye, SearchX } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import type { LiveChannel } from '@fapost/ui/lib/live-updates'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { useLiveUpdates } from '@fapost/ui/lib/useLiveUpdates'
import { interpolate } from '@fapost/ui/shell'
import CopyButton from '../Contacts/CopyButton.vue'
import LiveHint from './LiveHint.vue'
import { END_STATUS_TONES, STATUS_TONES, type ActivityPeriod, type FlowSessionRow, type FlowSessionStatus, type FlowSessionsPageProps } from './types'

const props = defineProps<{
  table: { rows: FlowSessionRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  statuses: FlowSessionStatus[]
  flows: { value: string; label: string }[]
  periods: ActivityPeriod[]
  live: LiveChannel | null
  urls: { index: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own. No status filter means "live".
const ANY = '__any'
const LIVE = 'live'
const ALL_STATUSES = 'all'

/** The interval the Filament list polled at. */
const POLL_MS = 30_000

const page = usePage<FlowSessionsPageProps>()
const t = computed(() => page.props.translations.console.flow_sessions)
const tableLabels = computed(() => page.props.translations.console.table)

const { transport } = useLiveUpdates({ live: () => props.live, only: ['table'], pollMs: POLL_MS })

const columns = computed<DataTableColumn[]>(() => [
  { key: 'shortId', label: t.value.columns.id },
  { key: 'status', label: t.value.columns.status },
  { key: 'endStatus', label: t.value.columns.end_status, class: 'hidden lg:table-cell' },
  { key: 'contactExternalId', label: t.value.columns.contact },
  { key: 'flowName', label: t.value.columns.flow, class: 'hidden md:table-cell' },
  { key: 'currentNodeId', label: t.value.columns.current_node, class: 'hidden lg:table-cell' },
  { key: 'updatedAt', sortKey: 'updated_at', label: t.value.columns.updated_at, sortable: true, class: 'hidden sm:table-cell' },
])

const showingLive = computed(() => (props.table.state.filters?.status ?? LIVE) === LIVE)

function endStatusLabel(value: string): string {
  return t.value.end_statuses[value] ?? value
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
      :row-label="(row) => row.shortId"
      :selectable="false"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters, setFilter }">
        <Select :model-value="filters.status ?? LIVE" @update:model-value="(value) => setFilter('status', value === LIVE ? '' : String(value))">
          <SelectTrigger size="sm" class="w-48" :aria-label="t.filters.status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="LIVE">{{ t.filters.status_live }}</SelectItem>
            <SelectItem :value="ALL_STATUSES">{{ t.filters.status_all }}</SelectItem>
            <SelectItem v-for="status in statuses" :key="status" :value="status">{{ t.statuses[status] }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="filters.flow ?? ANY" @update:model-value="(value) => setFilter('flow', value === ANY ? '' : String(value))">
          <SelectTrigger size="sm" class="w-44" :aria-label="t.filters.flow">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ANY">{{ t.filters.flow_all }}</SelectItem>
            <SelectItem v-for="flow in flows" :key="flow.value" :value="flow.value">{{ flow.label }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="filters.period ?? ANY" @update:model-value="(value) => setFilter('period', value === ANY ? '' : String(value))">
          <SelectTrigger size="sm" class="w-44" :aria-label="t.filters.period">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ANY">{{ t.filters.period_any }}</SelectItem>
            <SelectItem v-for="period in periods" :key="period" :value="period">{{ t.periods[period] }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #cell-shortId="{ row }">
        <span class="inline-flex items-center gap-1">
          <Link :href="row.viewUrl" class="font-mono text-xs font-medium hover:underline" :title="row.id">{{ row.shortId }}</Link>
          <CopyButton :value="row.id" />
        </span>
      </template>

      <template #cell-status="{ row }">
        <StatusDot :tone="STATUS_TONES[row.status]">{{ t.statuses[row.status] }}</StatusDot>
      </template>

      <template #cell-endStatus="{ row }">
        <Badge v-if="row.endStatus" :variant="END_STATUS_TONES[row.endStatus] ?? 'neutral'">{{ endStatusLabel(row.endStatus) }}</Badge>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-contactExternalId="{ row }">
        <span v-if="row.contactExternalId" class="block max-w-40 truncate font-mono text-sm" :title="row.contactExternalId">{{ row.contactExternalId }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-flowName="{ row }">
        <span v-if="row.flowName" class="block max-w-48 truncate" :title="row.flowName">{{ row.flowName }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-currentNodeId="{ row }">
        <span v-if="row.currentNodeId" class="font-mono text-xs">{{ row.currentNodeId }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-updatedAt="{ row }">
        <span v-if="row.updatedAt" class="text-muted-foreground" :title="row.updatedAt">{{ relativeTime(row.updatedAt, page.props.locale) }}</span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end">
          <Button as-child variant="ghost" size="icon-sm">
            <Link :href="row.viewUrl" :aria-label="interpolate(t.open_named, { name: row.shortId })">
              <Eye aria-hidden="true" />
            </Link>
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else-if="showingLive" :title="t.empty_live" :description="t.empty_hint" />
        <EmptyState v-else :title="t.empty" />
      </template>
    </DataTable>
  </div>
</template>
