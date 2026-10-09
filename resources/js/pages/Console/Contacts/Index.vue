<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Eye, SearchX } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Button } from '@fapost/ui/components/button'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import CopyButton from './CopyButton.vue'
import type { ContactRow, ContactsPageProps } from './types'

defineProps<{
  table: { rows: ContactRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  platforms: { value: string; label: string }[]
  languages: string[]
  urls: { index: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'

const page = usePage<ContactsPageProps>()
const t = computed(() => page.props.translations.console.contacts)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'shortId', label: t.value.columns.id, class: 'hidden md:table-cell' },
  { key: 'platform', label: t.value.columns.platform },
  { key: 'externalId', label: t.value.columns.external_id },
  { key: 'name', label: t.value.columns.name, class: 'hidden sm:table-cell' },
  { key: 'language', label: t.value.columns.language, class: 'hidden sm:table-cell' },
  { key: 'createdAt', sortKey: 'created_at', label: t.value.columns.created_at, sortable: true, class: 'hidden md:table-cell' },
])

function platformLabel(value: string): string {
  return t.value.platforms[value] ?? value
}

function label(row: ContactRow): string {
  return row.name ?? row.externalId
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
      :row-label="label"
      :selectable="false"
      has-actions
      :search-label="t.search_label"
    >
      <template #toolbar="{ filters, setFilter }">
        <Select :model-value="filters.platform ?? ALL" @update:model-value="(value) => setFilter('platform', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.platform">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.platform_all }}</SelectItem>
            <SelectItem v-for="option in platforms" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="filters.language ?? ALL" @update:model-value="(value) => setFilter('language', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.language">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.language_all }}</SelectItem>
            <SelectItem v-for="language in languages" :key="language" :value="language">{{ language }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #cell-shortId="{ row }">
        <span class="inline-flex items-center gap-1">
          <span class="font-mono text-xs" :title="row.id">{{ row.shortId }}</span>
          <CopyButton :value="row.id" />
        </span>
      </template>

      <template #cell-platform="{ row }">
        <Badge variant="info">{{ platformLabel(row.platform) }}</Badge>
      </template>

      <template #cell-externalId="{ row }">
        <Link :href="row.viewUrl" class="block max-w-40 truncate font-mono text-sm font-medium hover:underline" :title="row.externalId">{{ row.externalId }}</Link>
      </template>

      <template #cell-name="{ row }">
        <span v-if="row.name" class="block max-w-48 truncate" :title="row.name">{{ row.name }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-language="{ row }">
        <Badge v-if="row.language" variant="neutral">{{ row.language }}</Badge>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-createdAt="{ row }">
        <span v-if="row.createdAt" class="text-muted-foreground" :title="row.createdAt">{{ relativeTime(row.createdAt, page.props.locale) }}</span>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end">
          <Button as-child variant="ghost" size="icon-sm">
            <Link :href="row.viewUrl" :aria-label="interpolate(t.open_named, { name: label(row) })">
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
