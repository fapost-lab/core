<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { EllipsisVertical, Lock, LockOpen, Mail, Pencil, SearchX, Trash2, UserPlus } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@fapost/ui/shell'
import type { UserRow, UsersPageProps } from './types'

const props = defineProps<{
  table: { rows: UserRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  limit: { reached: boolean; hint: string | null }
  can: { create: boolean; delete: boolean }
  urls: { index: string; create: string; destroyMany: string }
}>()

const page = usePage<UsersPageProps>()
const t = computed(() => page.props.translations.console.users)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'email', label: t.value.columns.email, sortable: true, class: 'hidden md:table-cell' },
  { key: 'phone', label: t.value.columns.phone, class: 'hidden lg:table-cell' },
  { key: 'status', label: t.value.columns.status, sortable: true, class: 'hidden sm:table-cell' },
  { key: 'isActive', sortKey: 'is_active', label: t.value.columns.active, sortable: true },
  { key: 'roles', label: t.value.columns.roles, class: 'hidden md:table-cell' },
])

const statusTones: Record<string, 'success' | 'warning' | 'neutral'> = { active: 'success', pending: 'warning' }

// The targets stay set while a dialog closes, so its text does not change under the closing animation.
const activityOpen = ref(false)
const deleteManyOpen = ref(false)
const rowToToggle = ref<UserRow | null>(null)
const idsToDelete = ref<string[]>([])

function askAboutActivity(row: UserRow): void {
  rowToToggle.value = row
  activityOpen.value = true
}

function toggleActivity(): void {
  if (rowToToggle.value) {
    router.patch(rowToToggle.value.activityUrl, { active: !rowToToggle.value.isActive }, { preserveScroll: true })
  }
}

function resend(row: UserRow): void {
  router.post(row.resendUrl, {}, { preserveScroll: true })
}

function askAboutMany(selected: string[]): void {
  idsToDelete.value = [...selected]
  deleteManyOpen.value = true
}

function deleteMany(): void {
  router.delete(props.urls.destroyMany, { data: { ids: idsToDelete.value }, preserveScroll: true })
}
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
      :selectable="can.delete"
      :has-actions="true"
      :search-label="t.search_label"
    >
      <template #toolbar>
        <Button v-if="can.create" as-child>
          <Link :href="urls.create">
            <UserPlus aria-hidden="true" />
            {{ t.new }}
          </Link>
        </Button>
      </template>

      <template #bulk-actions="{ selected }">
        <Button variant="outline-danger" size="sm" @click="askAboutMany(selected)">
          <Trash2 aria-hidden="true" />
          {{ t.delete_selected }}
        </Button>
      </template>

      <template #cell-name="{ row }">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ row.name }}</span>
          <Badge v-if="row.isSupport" variant="warning">{{ t.support }}</Badge>
          <Badge v-if="row.isSelf" variant="info">{{ t.you }}</Badge>
        </div>
      </template>

      <template #cell-phone="{ row }">
        <span v-if="row.phone">{{ row.phone }}</span>
        <span v-else class="text-muted-foreground">—</span>
      </template>

      <template #cell-status="{ row }">
        <StatusDot :tone="statusTones[row.status] ?? 'neutral'">{{ row.statusLabel }}</StatusDot>
      </template>

      <template #cell-isActive="{ row }">
        <StatusDot :tone="row.isActive ? 'success' : 'danger'">{{ row.isActive ? t.active : t.blocked }}</StatusDot>
      </template>

      <template #cell-roles="{ row }">
        <div class="flex flex-wrap gap-1">
          <Badge v-for="role in row.roles" :key="role" variant="neutral">{{ role }}</Badge>
        </div>
      </template>

      <template #actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button v-if="row.can.edit" as-child variant="ghost" size="icon-sm">
            <Link :href="row.editUrl" :aria-label="interpolate(common.edit_named, { name: row.name })">
              <Pencil aria-hidden="true" />
            </Link>
          </Button>

          <DropdownMenu v-if="row.can.deactivate || row.can.activate || row.can.resend">
            <DropdownMenuTrigger as-child>
              <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions_for, { name: row.name })">
                <EllipsisVertical aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem v-if="row.can.resend" @select="resend(row)">
                <Mail aria-hidden="true" />
                {{ interpolate(t.actions.resend_activation, { name: row.name }) }}
              </DropdownMenuItem>
              <DropdownMenuItem v-if="row.can.activate" @select="askAboutActivity(row)">
                <LockOpen aria-hidden="true" />
                {{ interpolate(t.actions.activate, { name: row.name }) }}
              </DropdownMenuItem>
              <DropdownMenuItem v-if="row.can.deactivate" variant="destructive" @select="askAboutActivity(row)">
                <Lock aria-hidden="true" />
                {{ interpolate(t.actions.deactivate, { name: row.name }) }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>

    <ConfirmDialog
      v-model:open="activityOpen"
      :destructive="rowToToggle?.isActive ?? false"
      :title="rowToToggle?.isActive ? t.deactivate.title : t.activate.title"
      :description="interpolate(rowToToggle?.isActive ? t.deactivate.description : t.activate.description, { name: rowToToggle?.name ?? '' })"
      :confirm-label="rowToToggle?.isActive ? t.deactivate.confirm : t.activate.confirm"
      :cancel-label="common.cancel"
      @confirm="toggleActivity"
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
