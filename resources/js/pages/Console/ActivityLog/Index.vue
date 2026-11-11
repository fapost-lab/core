<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { Eye, SearchX } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@fapost/ui/components/sheet'
import { interpolate } from '@console/shell'
import { formatDateTime } from '../SupportAccess/datetime'
import { DEFAULT_PERIOD, isNarrowed, shownValue } from './filters'
import type { ActivityLogFilterOptions, ActivityLogPageProps, ActivityRow } from './types'

const props = defineProps<{
  table: { rows: ActivityRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  filters: ActivityLogFilterOptions
  urls: { index: string; supportAccess: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'

const page = usePage<ActivityLogPageProps>()
const t = computed(() => page.props.translations.console.activity_log)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'createdAt', sortKey: 'created_at', label: t.value.columns.time, sortable: true },
  { key: 'actorLabel', label: t.value.columns.actor },
  { key: 'actionLabel', label: t.value.columns.action },
  { key: 'subjectLabel', label: t.value.columns.subject, class: 'hidden md:table-cell' },
  { key: 'assistantName', label: t.value.columns.assistant, class: 'hidden lg:table-cell' },
])

const periodLabels = computed<Record<string, string>>(() => ({
  '7': t.value.filters.period_7,
  '30': t.value.filters.period_30,
  '90': t.value.filters.period_90,
  all: t.value.filters.period_all,
}))

const narrowed = computed(() => isNarrowed(props.table.state))

// The row stays set while the sheet closes, so its text does not change under the closing animation.
const sheetOpen = ref(false)
const selected = ref<ActivityRow | null>(null)

function open(row: ActivityRow): void {
  selected.value = row
  sheetOpen.value = true
}

function reset(): void {
  router.get(props.urls.index)
}

function actorName(row: ActivityRow): string {
  return row.actorLabel ?? t.value.actor_kind.system
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
      :row-label="(row) => row.actionLabel"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters: applied, setFilter }">
        <Select :model-value="applied.area ?? ALL" @update:model-value="(value) => setFilter('area', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.area">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.area_all }}</SelectItem>
            <SelectItem v-for="area in filters.areas" :key="area.key" :value="area.key">{{ area.label }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="applied.actor ?? ALL" @update:model-value="(value) => setFilter('actor', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.actor">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.actor_all }}</SelectItem>
            <SelectItem value="support">{{ t.filters.actor_support }}</SelectItem>
            <SelectItem value="system">{{ t.filters.actor_system }}</SelectItem>
            <SelectItem v-for="actor in filters.actors" :key="actor.id" :value="actor.id">{{ actor.name }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="applied.assistant ?? ALL" @update:model-value="(value) => setFilter('assistant', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.assistant">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.assistant_all }}</SelectItem>
            <SelectItem v-for="assistant in filters.assistants" :key="assistant.id" :value="assistant.id">{{ assistant.name }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="applied.period ?? DEFAULT_PERIOD" @update:model-value="(value) => setFilter('period', String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.period">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="period in filters.periods" :key="period" :value="period">{{ periodLabels[period] ?? period }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #cell-createdAt="{ row }">
        <time :datetime="row.createdAt">{{ formatDateTime(row.createdAt, page.props.locale) }}</time>
      </template>

      <template #cell-actorLabel="{ row }">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ actorName(row) }}</span>
          <Badge v-if="row.actorKind === 'support'" variant="warning">{{ t.actor_kind.support }}</Badge>
          <Badge v-else-if="row.actorKind === 'system'" variant="neutral">{{ t.actor_kind.system }}</Badge>
        </div>
      </template>

      <template #cell-actionLabel="{ row }">
        <div class="flex flex-col">
          <span>{{ row.actionLabel }}</span>
          <span class="text-muted-foreground text-sm">{{ row.areaLabel }}</span>
        </div>
      </template>

      <template #cell-subjectLabel="{ row }">
        <span v-if="row.subjectLabel">{{ row.subjectLabel }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-assistantName="{ row }">
        <span v-if="row.assistantName">{{ row.assistantName }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end">
          <Button variant="ghost" size="sm" :aria-label="interpolate(t.details_for, { name: row.actionLabel })" @click="open(row)">
            <Eye aria-hidden="true" />
            {{ t.details }}
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search">
          <Button v-if="narrowed" variant="outline" size="sm" @click="reset">{{ t.reset }}</Button>
        </EmptyState>
        <EmptyState v-else :title="t.empty" :description="t.empty_hint">
          <Button v-if="narrowed" variant="outline" size="sm" @click="reset">{{ t.reset }}</Button>
        </EmptyState>
      </template>
    </DataTable>

    <Sheet v-model:open="sheetOpen">
      <SheetContent class="w-full overflow-y-auto sm:max-w-md">
        <template v-if="selected">
          <SheetHeader>
            <SheetTitle>{{ selected.actionLabel }}</SheetTitle>
            <SheetDescription>
              {{ interpolate(t.sheet.description, { time: formatDateTime(selected.createdAt, page.props.locale) }) }}
            </SheetDescription>
          </SheetHeader>

          <div class="flex flex-col gap-5 px-4 pb-6">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ actorName(selected) }}</span>
              <Badge v-if="selected.actorKind === 'support'" variant="warning">{{ t.actor_kind.support }}</Badge>
              <Badge v-else-if="selected.actorKind === 'system'" variant="neutral">{{ t.actor_kind.system }}</Badge>
              <Badge variant="neutral">{{ selected.areaLabel }}</Badge>
            </div>

            <dl class="flex flex-col gap-1 text-sm">
              <div v-if="selected.subjectLabel" class="flex gap-2">
                <dt class="text-muted-foreground w-24 shrink-0">{{ t.columns.subject }}</dt>
                <dd class="min-w-0 break-words">{{ selected.subjectLabel }}</dd>
              </div>
              <div v-if="selected.assistantName" class="flex gap-2">
                <dt class="text-muted-foreground w-24 shrink-0">{{ t.columns.assistant }}</dt>
                <dd class="min-w-0 break-words">{{ selected.assistantName }}</dd>
              </div>
            </dl>

            <section v-if="selected.actorKind === 'support'" class="flex flex-col gap-1 text-sm">
              <span>{{ t.sheet.support }}</span>
              <Link :href="urls.supportAccess" class="text-primary font-medium underline-offset-2 hover:underline">
                {{ t.sheet.support_link }}
              </Link>
            </section>

            <section v-if="selected.values.length > 0" class="flex flex-col gap-2">
              <h3 class="font-semibold">{{ t.sheet.changes }}</h3>
              <div v-for="change in selected.values" :key="change.field" class="border-border flex flex-col gap-1 rounded-lg border p-3 text-sm">
                <span class="font-mono text-xs">{{ change.field }}</span>
                <div class="flex gap-2">
                  <span class="text-muted-foreground w-12 shrink-0">{{ t.sheet.old }}</span>
                  <span class="min-w-0 break-words">{{ shownValue(change.old, t.sheet.empty_value) }}</span>
                </div>
                <div class="flex gap-2">
                  <span class="text-muted-foreground w-12 shrink-0">{{ t.sheet.new }}</span>
                  <span class="min-w-0 font-medium break-words">{{ shownValue(change.new, t.sheet.empty_value) }}</span>
                </div>
              </div>
            </section>

            <section v-if="selected.markers.length > 0" class="flex flex-col gap-2">
              <h3 class="font-semibold">{{ t.sheet.markers }}</h3>
              <p class="text-muted-foreground text-sm">{{ t.sheet.markers_hint }}</p>
              <div class="flex flex-wrap gap-1.5">
                <Badge v-for="field in selected.markers" :key="field" variant="neutral" class="font-mono">{{ field }}</Badge>
              </div>
            </section>

            <section v-if="selected.properties.length > 0" class="flex flex-col gap-2">
              <h3 class="font-semibold">{{ t.sheet.properties }}</h3>
              <dl class="flex flex-col gap-1 text-sm">
                <div v-for="property in selected.properties" :key="property.key" class="flex gap-2">
                  <dt class="text-muted-foreground w-32 shrink-0 font-mono text-xs">{{ property.key }}</dt>
                  <dd class="min-w-0 break-words">{{ property.value }}</dd>
                </div>
              </dl>
            </section>

            <p
              v-if="selected.values.length === 0 && selected.markers.length === 0 && selected.properties.length === 0"
              class="text-muted-foreground text-sm"
            >
              {{ t.sheet.no_details }}
            </p>
          </div>
        </template>
      </SheetContent>
    </Sheet>
  </div>
</template>
