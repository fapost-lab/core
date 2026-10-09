<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { CircleCheck, CircleX, EllipsisVertical, Pause, Pencil, Play, Plus, SquarePen, Trash2 } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { Label } from '@fapost/ui/components/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import { interpolate } from '@fapost/ui/shell'
import TriggerHint from './TriggerHint.vue'
import type { FlowGroupOption, FlowRow, FlowsPageProps } from './types'

const props = defineProps<{
  table: { rows: FlowRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  groups: FlowGroupOption[]
  limit: { reached: boolean; hint: string | null }
  can: { create: boolean; update: boolean; delete: boolean }
  urls: { index: string; create: string; destroyMany: string }
}>()

// A select item cannot hold an empty value, so "no filter" is a value of its own.
const ALL = '__all'

const page = usePage<FlowsPageProps>()
const t = computed(() => page.props.translations.console.flows)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'isActive', label: t.value.filters.active, class: 'w-10' },
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'groupName', sortKey: 'group_name', label: t.value.columns.group, sortable: true, class: 'hidden md:table-cell' },
  { key: 'isPublic', label: t.value.columns.visibility, class: 'hidden sm:table-cell' },
  { key: 'publishedVersion', label: t.value.columns.version, class: 'hidden sm:table-cell' },
])

const hasMenu = computed(() => props.can.update || props.can.delete)

function groupOf(row: FlowRow): { key: string; label: string } {
  return { key: row.groupId ?? '', label: row.groupName ?? t.value.ungrouped }
}

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const deleteOneOpen = ref(false)
const deleteManyOpen = ref(false)
const activityOpen = ref(false)
const rowToDelete = ref<FlowRow | null>(null)
const rowToToggle = ref<FlowRow | null>(null)
const idsToDelete = ref<string[]>([])

function askAboutOne(row: FlowRow): void {
  rowToDelete.value = row
  deleteOneOpen.value = true
}

function askAboutMany(selected: string[]): void {
  idsToDelete.value = [...selected]
  deleteManyOpen.value = true
}

function askAboutActivity(row: FlowRow): void {
  rowToToggle.value = row
  activityOpen.value = true
}

function deleteOne(): void {
  if (rowToDelete.value) {
    router.delete(rowToDelete.value.deleteUrl, { preserveScroll: true })
  }
}

function deleteMany(): void {
  router.delete(props.urls.destroyMany, { data: { ids: idsToDelete.value }, preserveScroll: true })
}

// The state asked for is sent, not "switch it", so a repeated request changes nothing.
function toggleActivity(): void {
  if (rowToToggle.value) {
    router.patch(rowToToggle.value.activityUrl, { active: !rowToToggle.value.isActive }, { preserveScroll: true })
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
      :row-label="(row) => row.name"
      :selectable="can.delete"
      has-actions
      :search-label="t.search_label"
      :group-of="groupOf"
    >
      <template #toolbar="{ filters, setFilter, group, setGroup }">
        <Select :model-value="filters.group ?? ALL" @update:model-value="(value) => setFilter('group', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.group">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.group_all }}</SelectItem>
            <SelectItem v-for="option in groups" :key="option.id" :value="option.id">{{ option.name }}</SelectItem>
          </SelectContent>
        </Select>

        <Select :model-value="filters.active ?? ALL" @update:model-value="(value) => setFilter('active', value === ALL ? '' : String(value))">
          <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.active">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="ALL">{{ t.filters.active_all }}</SelectItem>
            <SelectItem value="1">{{ t.filters.active_yes }}</SelectItem>
            <SelectItem value="0">{{ t.filters.active_no }}</SelectItem>
          </SelectContent>
        </Select>

        <div class="flex items-center gap-2">
          <Switch id="group-rows" :model-value="group !== ''" @update:model-value="(value) => setGroup(value ? 'group' : '')" />
          <Label for="group-rows" class="text-sm font-normal">{{ t.group_rows }}</Label>
        </div>

        <Button v-if="can.create" as-child>
          <Link :href="urls.create">
            <Plus aria-hidden="true" />
            {{ t.new }}
          </Link>
        </Button>
      </template>

      <template #bulk-actions="{ selected }">
        <Button variant="destructive" size="sm" @click="askAboutMany(selected)">
          <Trash2 aria-hidden="true" />
          {{ t.delete_selected }}
        </Button>
      </template>

      <template #cell-isActive="{ row }">
        <CircleCheck v-if="row.isActive" class="size-5 text-green-600 dark:text-green-500" role="img" :aria-label="t.status.active" />
        <CircleX v-else class="text-muted-foreground size-5" role="img" :aria-label="t.status.inactive" />
      </template>

      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
        <TriggerHint v-if="row.trigger" :trigger="row.trigger" />
      </template>

      <template #cell-groupName="{ row }">
        <span v-if="row.groupName">{{ row.groupName }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-isPublic="{ row }">
        <Badge :variant="row.isPublic ? 'default' : 'secondary'">{{ row.isPublic ? t.visibility.public : t.visibility.private }}</Badge>
      </template>

      <template #cell-publishedVersion="{ row }">
        <span v-if="row.publishedVersion !== null">v{{ row.publishedVersion }}</span>
        <span v-else class="text-muted-foreground" :title="t.no_version">—</span>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-1">
          <Button as-child variant="outline" size="sm">
            <a :href="row.builderUrl" target="_blank" rel="noopener" :aria-label="interpolate(t.builder_named, { name: row.name })">
              <SquarePen aria-hidden="true" />
              {{ t.builder }}
            </a>
          </Button>

          <DropdownMenu v-if="hasMenu">
            <DropdownMenuTrigger as-child>
              <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: row.name })">
                <EllipsisVertical aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <template v-if="can.update">
                <DropdownMenuItem as-child>
                  <Link :href="row.editUrl">
                    <Pencil aria-hidden="true" />
                    {{ common.edit }}
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuItem @select="askAboutActivity(row)">
                  <Pause v-if="row.isActive" aria-hidden="true" />
                  <Play v-else aria-hidden="true" />
                  {{ row.isActive ? t.deactivate : t.activate }}
                </DropdownMenuItem>
              </template>
              <template v-if="can.delete">
                <DropdownMenuSeparator v-if="can.update" />
                <DropdownMenuItem variant="destructive" @select="askAboutOne(row)">
                  <Trash2 aria-hidden="true" />
                  {{ common.delete }}
                </DropdownMenuItem>
              </template>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
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
      v-model:open="activityOpen"
      :destructive="rowToToggle?.isActive ?? false"
      :title="rowToToggle?.isActive ? t.deactivate_dialog.title : t.activate_dialog.title"
      :description="interpolate(rowToToggle?.isActive ? t.deactivate_dialog.description : t.activate_dialog.description, { name: rowToToggle?.name ?? '' })"
      :confirm-label="rowToToggle?.isActive ? t.deactivate : t.activate"
      :cancel-label="common.cancel"
      @confirm="toggleActivity"
    />

    <ConfirmDialog
      v-model:open="deleteOneOpen"
      :title="t.delete_one.title"
      :description="interpolate(t.delete_one.description, { name: rowToDelete?.name ?? '' })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="deleteOne"
    />

    <ConfirmDialog
      v-model:open="deleteManyOpen"
      :title="t.delete_many.title"
      :description="interpolate(t.delete_many.description, { count: idsToDelete.length })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="deleteMany"
    />
  </div>
</template>
