<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3'
import { ArrowLeft, Megaphone } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { BROADCAST_TONES, RECIPIENT_TONES } from './tones'
import type { BroadcastOutcomes, BroadcastSummary, BroadcastsPageProps, RecipientRow, RecipientStatus } from './types'

/**
 * The report of one broadcast: its outcomes, then every recipient with theirs. While the run is going the page asks for
 * fresh numbers, as the list does, and gives up after an hour on the page.
 */
const props = defineProps<{
  broadcast: BroadcastSummary
  outcomes: BroadcastOutcomes
  table: { rows: RecipientRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  statuses: RecipientStatus[]
  urls: { index: string; show: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'

const POLL_MS = 5000
const POLL_LIMIT_MS = 60 * 60 * 1000

const page = usePage<BroadcastsPageProps>()
const list = computed(() => page.props.translations.console.broadcasts)
const t = computed(() => list.value.report)

const number = computed(() => new Intl.NumberFormat(page.props.locale))

const isDraft = computed(() => props.broadcast.status === 'draft')
const isPreparing = computed(() => props.broadcast.status === 'running' && props.outcomes.total === 0)

const tiles = computed(() => [
  { key: 'total', value: props.outcomes.total, tone: null },
  { key: 'sent', value: props.outcomes.sent, tone: 'success' },
  { key: 'pending', value: props.outcomes.pending, tone: 'info' },
  { key: 'failed', value: props.outcomes.failed, tone: 'danger' },
  { key: 'skipped', value: props.outcomes.skipped, tone: 'neutral' },
] as const)

const dates = computed(() =>
  [
    { key: 'created', label: t.value.created, value: props.broadcast.createdAt },
    { key: 'started', label: t.value.started, value: props.broadcast.startedAt },
    { key: 'completed', label: t.value.completed, value: props.broadcast.completedAt },
  ].filter((entry): entry is { key: string; label: string; value: string } => entry.value !== null),
)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'contact', label: t.value.recipients.columns.contact },
  { key: 'status', label: t.value.recipients.columns.status },
  { key: 'reason', label: t.value.recipients.columns.reason, class: 'hidden md:table-cell' },
  { key: 'sentAt', label: t.value.recipients.columns.sent, class: 'hidden sm:table-cell' },
  { key: 'updatedAt', sortKey: 'updated_at', label: t.value.recipients.columns.updated, sortable: true, class: 'hidden lg:table-cell' },
])

const isFiltered = computed(() => Boolean(props.table.state.filters?.status))

// Polling runs only while the broadcast is running, and stops after an hour on the page.
const poll = usePoll(POLL_MS, { only: ['broadcast', 'outcomes', 'table'] }, { autoStart: false })
const isRunning = computed(() => props.broadcast.status === 'running')
let pollDeadline: ReturnType<typeof setTimeout> | null = null
let pollExpired = false

watch(
  isRunning,
  (running) => {
    if (running && !pollExpired) {
      poll.start()
      pollDeadline ??= setTimeout(() => {
        pollExpired = true
        poll.stop()
      }, POLL_LIMIT_MS)
    } else {
      poll.stop()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (pollDeadline !== null) {
    clearTimeout(pollDeadline)
  }
})
</script>

<template>
  <Head :title="broadcast.name" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-2">
      <Button as-child variant="ghost" size="sm" class="-ml-2 w-fit">
        <Link :href="urls.index">
          <ArrowLeft aria-hidden="true" />
          {{ t.back }}
        </Link>
      </Button>
      <div class="flex flex-col gap-1">
        <h1 class="font-display min-w-0 text-[28px] leading-tight font-semibold break-words">{{ broadcast.name }}</h1>
        <p class="text-muted-foreground">{{ t.description }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm" data-test="broadcast-summary">
        <StatusDot :tone="BROADCAST_TONES[broadcast.status]">{{ list.statuses[broadcast.status] }}</StatusDot>
        <Badge variant="neutral">{{ list.targets[broadcast.target] }}</Badge>
        <span v-if="broadcast.origin === 'automation'" class="text-muted-foreground flex items-center gap-2" data-test="broadcast-origin">
          <Badge variant="neutral">{{ list.origin.automation }}</Badge>
          <template v-if="broadcast.flowName">
            <a v-if="broadcast.flowUrl" :href="broadcast.flowUrl" class="hover:underline">{{ broadcast.flowName }}</a>
            <span v-else>{{ broadcast.flowName }}</span>
          </template>
        </span>
        <span v-for="entry in dates" :key="entry.key" class="text-muted-foreground">
          {{ entry.label }}: <span :title="entry.value">{{ relativeTime(entry.value, page.props.locale) }}</span>
        </span>
      </div>
      <p v-if="broadcast.stopReason && list.stop_reasons[broadcast.stopReason]" class="text-muted-foreground text-sm" data-test="stop-reason">
        {{ list.stop_reasons[broadcast.stopReason] }}
      </p>
    </div>

    <EmptyState v-if="isDraft" :icon="Megaphone" :title="t.not_started" class="rounded-xl border border-dashed py-12" data-test="report-draft" />

    <template v-else>
      <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-5" data-test="outcomes">
        <Card v-for="tile in tiles" :key="tile.key" :data-test="`outcome-${tile.key}`">
          <CardHeader>
            <CardDescription>
              <StatusDot v-if="tile.tone" :tone="tile.tone">{{ t.outcomes[tile.key] }}</StatusDot>
              <span v-else>{{ t.outcomes[tile.key] }}</span>
            </CardDescription>
            <CardTitle class="font-display text-3xl font-semibold tabular-nums">{{ number.format(tile.value) }}</CardTitle>
          </CardHeader>
        </Card>
      </div>

      <p class="text-muted-foreground text-[13px]">{{ isPreparing ? t.preparing : t.sent_note }}</p>

      <section class="flex flex-col gap-3">
        <h2 class="font-display text-lg font-semibold">{{ t.recipients.title }}</h2>

        <DataTable
          :columns="columns"
          :rows="table.rows"
          :meta="table.meta"
          :state="table.state"
          :defaults="table.defaults"
          :url="urls.show"
          :row-label="(row) => row.contact ?? row.externalId ?? row.id"
          :searchable="false"
        >
          <template #toolbar="{ filters, setFilter }">
            <Select :model-value="filters.status ?? ALL" @update:model-value="(value) => setFilter('status', value === ALL ? '' : String(value))">
              <SelectTrigger size="sm" class="w-44" :aria-label="t.recipients.status">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="ALL">{{ t.recipients.status_all }}</SelectItem>
                <SelectItem v-for="status in statuses" :key="status" :value="status">{{ t.statuses[status] }}</SelectItem>
              </SelectContent>
            </Select>
          </template>

          <template #cell-contact="{ row }">
            <component :is="row.contactUrl ? Link : 'span'" :href="row.contactUrl ?? undefined" :class="row.contactUrl ? 'font-medium hover:underline' : 'font-medium'">
              {{ row.contact ?? row.externalId ?? '—' }}
            </component>
            <p v-if="row.contact && row.externalId" class="text-muted-foreground mt-0.5 font-mono text-xs">{{ row.externalId }}</p>
          </template>

          <template #cell-status="{ row }">
            <StatusDot :tone="RECIPIENT_TONES[row.status]">{{ t.statuses[row.status] }}</StatusDot>
            <p v-if="row.reason" class="text-muted-foreground mt-0.5 text-xs md:hidden">{{ t.reasons[row.reason] }}</p>
          </template>

          <template #cell-reason="{ row }">
            <span v-if="row.reason" class="text-muted-foreground" data-test="recipient-reason">{{ t.reasons[row.reason] }}</span>
            <span v-else class="text-muted-foreground">—</span>
          </template>

          <template #cell-sentAt="{ row }">
            <span v-if="row.sentAt" class="text-muted-foreground" :title="row.sentAt">{{ relativeTime(row.sentAt, page.props.locale) }}</span>
            <span v-else class="text-muted-foreground">—</span>
          </template>

          <template #cell-updatedAt="{ row }">
            <span v-if="row.updatedAt" class="text-muted-foreground" :title="row.updatedAt">{{ relativeTime(row.updatedAt, page.props.locale) }}</span>
          </template>

          <template #empty>
            <EmptyState v-if="isFiltered" :title="t.recipients.filtered" />
            <EmptyState v-else :title="t.recipients.empty" :description="t.recipients.empty_hint" />
          </template>
        </DataTable>
      </section>
    </template>
  </div>
</template>
