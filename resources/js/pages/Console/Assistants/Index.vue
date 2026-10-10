<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Eye, EllipsisVertical, Pencil, Plus, SearchX, SquareArrowOutUpRight } from '@lucide/vue'
import { StatusDot, Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import type { AssistantRow, AssistantsPageProps } from './types'

defineProps<{
  table: { rows: AssistantRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  limit: { reached: boolean; hint: string | null }
  can: { create: boolean }
  urls: { index: string; create: string }
}>()

const page = usePage<AssistantsPageProps>()
const t = computed(() => page.props.translations.console.assistants)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'isActive', sortKey: 'is_active', label: t.value.columns.active, sortable: true },
  { key: 'defaultLanguage', sortKey: 'default_language', label: t.value.columns.language, sortable: true, class: 'hidden sm:table-cell' },
  { key: 'updatedAt', sortKey: 'updated_at', label: t.value.columns.updated, sortable: true, class: 'hidden md:table-cell' },
])
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground">{{ t.description }}</p>
      <p v-if="limit.hint" class="text-destructive text-sm font-medium" role="status">{{ limit.hint }}</p>
    </div>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      :row-label="(row) => row.name"
      :has-actions="true"
      :search-label="t.search_label"
    >
      <template #toolbar>
        <Button v-if="can.create" as-child class="ml-auto">
          <Link :href="urls.create">
            <Plus aria-hidden="true" />
            {{ t.new }}
          </Link>
        </Button>
      </template>

      <template #cell-name="{ row }">
        <Link v-if="row.can.view" :href="row.showUrl" class="font-medium underline-offset-4 hover:underline">{{ row.name }}</Link>
        <span v-else class="font-medium">{{ row.name }}</span>
      </template>

      <template #cell-isActive="{ row }">
        <StatusDot :tone="row.isActive ? 'success' : 'neutral'">{{ row.isActive ? t.status.active : t.status.inactive }}</StatusDot>
      </template>

      <template #cell-defaultLanguage="{ row }">
        <Badge variant="neutral" :title="row.languageLabel">{{ row.defaultLanguage }}</Badge>
      </template>

      <template #cell-updatedAt="{ row }">
        <span v-if="row.updatedAt" class="text-muted-foreground" :title="row.updatedAt">{{ relativeTime(row.updatedAt, page.props.locale) }}</span>
      </template>

      <template #actions="{ row }">
        <DropdownMenu v-if="row.can.view">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: row.name })">
              <EllipsisVertical aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem as-child>
              <Link :href="row.consoleUrl">
                <SquareArrowOutUpRight aria-hidden="true" />
                {{ t.manage }}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
              <Link :href="row.showUrl">
                <Eye aria-hidden="true" />
                {{ t.view }}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem v-if="row.can.update" as-child>
              <Link :href="row.editUrl">
                <Pencil aria-hidden="true" />
                {{ common.edit }}
              </Link>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>
  </div>
</template>
