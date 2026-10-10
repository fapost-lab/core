<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import {
  ArchiveRestore,
  ChevronRight,
  EllipsisVertical,
  Eye,
  FolderInput,
  FolderPlus,
  Link2,
  Pencil,
  SearchX,
  Trash2,
  Upload,
} from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent } from '@fapost/ui/components/card'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import {
  buildQuery,
  DataTable,
  type DataTableColumn,
  type TableDefaults,
  type TableMeta,
  type TableState,
} from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import DeleteFolderDialog from './DeleteFolderDialog.vue'
import FolderDialog from './FolderDialog.vue'
import FolderTree from './FolderTree.vue'
import MoveDialog from './MoveDialog.vue'
import RenameDialog from './RenameDialog.vue'
import UploadDialog from './UploadDialog.vue'
import { folderState } from './folders'
import type { FolderNode, MediaPageProps, MediaRow, Option } from './types'

const props = defineProps<{
  table: { rows: MediaRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  folder: { id: string; name: string } | null
  breadcrumbs: { id: string; name: string }[]
  tree: FolderNode[]
  kinds: Option[]
  upload: { maxFiles: number; maxSize: string; accept: string }
  can: { manage: boolean }
  urls: { index: string; upload: string; move: string; destroyMany: string; storeFolder: string }
}>()

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const ALL = '__all__'

const columns = computed<DataTableColumn[]>(() => [
  { key: 'kind', label: t.value.columns.kind, class: 'hidden sm:table-cell' },
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'size', label: t.value.columns.size, class: 'hidden md:table-cell' },
  { key: 'references', label: t.value.columns.references, class: 'hidden md:table-cell' },
  { key: 'createdAt', sortKey: 'created_at', label: t.value.columns.created, sortable: true, class: 'hidden lg:table-cell' },
])

const kindTones: Record<string, 'success' | 'warning' | 'info' | 'neutral'> = { image: 'success', video: 'warning', audio: 'info' }

const currentFolderId = computed(() => props.folder?.id ?? null)

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const uploadOpen = ref(false)
const folderOpen = ref(false)
const deleteFolderOpen = ref(false)
const renameOpen = ref(false)
const moveOpen = ref(false)
const deleteOpen = ref(false)
const deleteManyOpen = ref(false)
const forceOpen = ref(false)
const folderToEdit = ref<FolderNode | null>(null)
const folderToDelete = ref<FolderNode | null>(null)
const rowInFocus = ref<MediaRow | null>(null)
const idsToMove = ref<string[]>([])
const idsToDelete = ref<string[]>([])

function openFolder(folderId: string | null): void {
  router.get(props.urls.index, buildQuery(folderState(props.table.state, folderId), props.table.defaults), { preserveScroll: true })
}

function newFolder(): void {
  folderToEdit.value = null
  folderOpen.value = true
}

function renameFolder(folder: FolderNode): void {
  folderToEdit.value = folder
  folderOpen.value = true
}

function deleteFolder(folder: FolderNode): void {
  folderToDelete.value = folder
  deleteFolderOpen.value = true
}

function rename(row: MediaRow): void {
  rowInFocus.value = row
  renameOpen.value = true
}

function move(ids: string[]): void {
  idsToMove.value = [...ids]
  moveOpen.value = true
}

function askToDelete(row: MediaRow): void {
  rowInFocus.value = row
  deleteOpen.value = true
}

function askToDeleteMany(ids: string[]): void {
  idsToDelete.value = [...ids]
  deleteManyOpen.value = true
}

function askToForceDelete(row: MediaRow): void {
  rowInFocus.value = row
  forceOpen.value = true
}

function destroy(): void {
  if (rowInFocus.value) {
    router.delete(rowInFocus.value.destroyUrl, { preserveScroll: true })
  }
}

function destroyMany(): void {
  router.delete(props.urls.destroyMany, { data: { ids: idsToDelete.value }, preserveScroll: true })
}

function restore(row: MediaRow): void {
  router.post(row.restoreUrl, {}, { preserveScroll: true })
}

function forceDelete(): void {
  if (rowInFocus.value) {
    router.delete(rowInFocus.value.forceUrl, { preserveScroll: true })
  }
}

const deleteDescription = computed(() => {
  const row = rowInFocus.value

  if (row && row.references > 0) {
    return interpolate(t.value.delete_one.referenced, { name: row.name, count: row.references })
  }

  return interpolate(t.value.delete_one.description, { name: row?.name ?? '' })
})
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
        <p class="text-muted-foreground">{{ t.description }}</p>
      </div>

      <div v-if="can.manage" class="flex flex-wrap gap-2">
        <Button variant="outline" @click="newFolder">
          <FolderPlus aria-hidden="true" />
          {{ t.new_folder }}
        </Button>
        <Button @click="uploadOpen = true">
          <Upload aria-hidden="true" />
          {{ t.upload }}
        </Button>
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
      <Card class="h-fit">
        <CardContent class="p-2">
          <FolderTree :tree="tree" :current-id="currentFolderId" :can-manage="can.manage" @open="openFolder" @rename="renameFolder" @delete="deleteFolder" />
        </CardContent>
      </Card>

      <div class="flex min-w-0 flex-col gap-3">
        <nav :aria-label="t.breadcrumbs" class="text-muted-foreground flex flex-wrap items-center gap-1 text-sm">
          <button type="button" class="hover:text-foreground" :class="{ 'text-foreground font-semibold': folder === null }" @click="openFolder(null)">{{ t.root }}</button>
          <template v-for="(crumb, index) in breadcrumbs" :key="crumb.id">
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <button
              type="button"
              class="hover:text-foreground"
              :class="{ 'text-foreground font-semibold': index === breadcrumbs.length - 1 }"
              :aria-current="index === breadcrumbs.length - 1 ? 'page' : undefined"
              @click="openFolder(crumb.id)"
            >
              {{ crumb.name }}
            </button>
          </template>
        </nav>

        <DataTable
          :columns="columns"
          :rows="table.rows"
          :meta="table.meta"
          :state="table.state"
          :defaults="table.defaults"
          :url="urls.index"
          :row-label="(row) => row.name"
          :selectable="can.manage"
          :has-actions="true"
          :search-label="t.search_label"
        >
          <template #toolbar="{ filters, setFilter }">
            <Select :model-value="filters.kind ?? ALL" @update:model-value="(value) => setFilter('kind', value === ALL ? '' : String(value))">
              <SelectTrigger size="sm" class="w-36" :aria-label="t.filters.kind">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="ALL">{{ t.filters.kind_all }}</SelectItem>
                <SelectItem v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</SelectItem>
              </SelectContent>
            </Select>

            <Select :model-value="filters.trashed ?? ALL" @update:model-value="(value) => setFilter('trashed', value === ALL ? '' : String(value))">
              <SelectTrigger size="sm" class="w-40" :aria-label="t.filters.trashed">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="ALL">{{ t.filters.trashed_without }}</SelectItem>
                <SelectItem value="with">{{ t.filters.trashed_with }}</SelectItem>
                <SelectItem value="only">{{ t.filters.trashed_only }}</SelectItem>
              </SelectContent>
            </Select>
          </template>

          <template #bulk-actions="{ selected }">
            <Button variant="outline" size="sm" @click="move(selected)">
              <FolderInput aria-hidden="true" />
              {{ t.move_selected }}
            </Button>
            <Button variant="outline-danger" size="sm" @click="askToDeleteMany(selected)">
              <Trash2 aria-hidden="true" />
              {{ t.delete_selected }}
            </Button>
          </template>

          <template #cell-kind="{ row }">
            <Badge :variant="kindTones[row.kind] ?? 'neutral'">{{ row.kindLabel }}</Badge>
          </template>

          <template #cell-name="{ row }">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
              <Link :href="row.viewUrl" class="truncate font-medium hover:underline" :class="{ 'text-muted-foreground line-through': row.trashed }">{{ row.name }}</Link>
              <Badge v-if="row.trashed" variant="danger">{{ t.in_trash }}</Badge>
            </div>
          </template>

          <template #cell-references="{ row }">
            <Badge :variant="row.references > 0 ? 'warning' : 'neutral'">{{ row.references }}</Badge>
          </template>

          <template #cell-createdAt="{ row }">
            <span v-if="row.createdAt" class="text-muted-foreground" :title="row.createdAt">{{ relativeTime(row.createdAt, page.props.locale) }}</span>
          </template>

          <template #actions="{ row }">
            <div class="flex justify-end gap-1">
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: row.name })">
                    <EllipsisVertical aria-hidden="true" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem as-child>
                    <Link :href="row.viewUrl">
                      <Eye aria-hidden="true" />
                      {{ t.actions.view }}
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem v-if="row.references > 0" as-child>
                    <Link :href="`${row.viewUrl}#references`">
                      <Link2 aria-hidden="true" />
                      {{ t.actions.references }}
                    </Link>
                  </DropdownMenuItem>
                  <template v-if="can.manage">
                    <DropdownMenuItem @select="rename(row)">
                      <Pencil aria-hidden="true" />
                      {{ t.actions.rename }}
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="move([row.id])">
                      <FolderInput aria-hidden="true" />
                      {{ t.actions.move }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem v-if="!row.trashed" variant="destructive" @select="askToDelete(row)">
                      <Trash2 aria-hidden="true" />
                      {{ t.actions.delete }}
                    </DropdownMenuItem>
                    <template v-else>
                      <DropdownMenuItem @select="restore(row)">
                        <ArchiveRestore aria-hidden="true" />
                        {{ t.actions.restore }}
                      </DropdownMenuItem>
                      <DropdownMenuItem variant="destructive" @select="askToForceDelete(row)">
                        <Trash2 aria-hidden="true" />
                        {{ t.actions.force_delete }}
                      </DropdownMenuItem>
                    </template>
                  </template>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </template>

          <template #empty="{ searching }">
            <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
            <EmptyState v-else :title="t.empty" :description="can.manage ? t.empty_hint : undefined">
              <Button v-if="can.manage" size="sm" @click="uploadOpen = true">
                <Upload aria-hidden="true" />
                {{ t.upload }}
              </Button>
            </EmptyState>
          </template>
        </DataTable>
      </div>
    </div>

    <UploadDialog v-model:open="uploadOpen" :url="urls.upload" :tree="tree" :folder-id="currentFolderId" :limits="upload" />
    <FolderDialog v-model:open="folderOpen" :tree="tree" :folder="folderToEdit" :store-url="urls.storeFolder" :parent-id="currentFolderId" />
    <DeleteFolderDialog v-model:open="deleteFolderOpen" :folder="folderToDelete" :tree="tree" />
    <RenameDialog v-model:open="renameOpen" :row="rowInFocus" />
    <MoveDialog v-model:open="moveOpen" :url="urls.move" :ids="idsToMove" :tree="tree" :folder-id="currentFolderId" />

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t.delete_one.title"
      :description="deleteDescription"
      :confirm-label="t.actions.delete"
      :cancel-label="common.cancel"
      @confirm="destroy"
    />

    <ConfirmDialog
      v-model:open="deleteManyOpen"
      :title="t.delete_many.title"
      :description="interpolate(t.delete_many.description, { count: idsToDelete.length })"
      :confirm-label="t.actions.delete"
      :cancel-label="common.cancel"
      @confirm="destroyMany"
    />

    <ConfirmDialog
      v-model:open="forceOpen"
      :title="t.force_delete.title"
      :description="interpolate(t.force_delete.description, { name: rowInFocus?.name ?? '' })"
      :confirm-label="t.actions.force_delete"
      :cancel-label="common.cancel"
      @confirm="forceDelete"
    />
  </div>
</template>
