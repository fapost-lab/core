<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Plus, RefreshCw, SearchX, Trash2 } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { DataTable, type DataTableColumn, type TableDefaults, type TableMeta, type TableState } from '@fapost/ui/components/data-table'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@console/shell'
import RevealDialog from './RevealDialog.vue'
import TokenFormDialog from './TokenFormDialog.vue'
import type { ApiTokenRow, ApiTokensPageProps, GrantablePermission, TokenStatus } from './types'

defineProps<{
  table: { rows: ApiTokenRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  grantable: GrantablePermission[]
  lifetime: { default: number; max: number }
  can: { manage: boolean }
  urls: { index: string; store: string }
}>()

const TONES = { active: 'success', expired: 'warning', revoked: 'neutral' } as const satisfies Record<TokenStatus, string>

const page = usePage<ApiTokensPageProps>()
const t = computed(() => page.props.translations.console.api_tokens)
const common = computed(() => page.props.translations.console.form)
const tableLabels = computed(() => page.props.translations.console.table)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t.value.columns.name, sortable: true },
  { key: 'last_four', label: t.value.columns.token, class: 'hidden sm:table-cell' },
  { key: 'abilities', label: t.value.columns.abilities, class: 'hidden md:table-cell' },
  { key: 'issuer', label: t.value.columns.issuer, class: 'hidden xl:table-cell' },
  { key: 'expires_at', label: t.value.columns.expires, sortable: true, class: 'hidden lg:table-cell' },
  { key: 'last_used_at', label: t.value.columns.last_used, sortable: true, class: 'hidden lg:table-cell' },
  { key: 'status', label: t.value.columns.status },
])

// A target stays set while its dialog closes, so the text does not change under the closing animation.
const formOpen = ref(false)
const rowToRotate = ref<ApiTokenRow | null>(null)
const revokeOpen = ref(false)
const rowToRevoke = ref<ApiTokenRow | null>(null)

function issue(): void {
  rowToRotate.value = null
  formOpen.value = true
}

function rotate(row: ApiTokenRow): void {
  rowToRotate.value = row
  formOpen.value = true
}

function askToRevoke(row: ApiTokenRow): void {
  rowToRevoke.value = row
  revokeOpen.value = true
}

function revoke(): void {
  if (rowToRevoke.value) {
    router.delete(rowToRevoke.value.revokeUrl, { preserveScroll: true })
  }
}

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(page.props.locale, { dateStyle: 'medium' }).format(new Date(iso))
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground">{{ t.description }}</p>
    </div>

    <p role="note" class="border-warning/40 bg-warning/10 rounded-md border px-3 py-2 text-[13px]">{{ t.address_note }}</p>

    <DataTable
      :columns="columns"
      :rows="table.rows"
      :meta="table.meta"
      :state="table.state"
      :defaults="table.defaults"
      :url="urls.index"
      :row-label="(row) => row.name"
      :has-actions="can.manage"
      :search-label="t.search_label"
    >
      <template #toolbar>
        <Button v-if="can.manage" :disabled="grantable.length === 0" @click="issue">
          <Plus aria-hidden="true" />
          {{ t.new }}
        </Button>
      </template>

      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
      </template>

      <template #cell-last_four="{ row }">
        <code class="text-muted-foreground font-mono text-[13px]">…{{ row.last_four }}</code>
      </template>

      <template #cell-abilities="{ row }">
        <div class="flex flex-wrap gap-1">
          <Badge v-for="ability in row.abilities" :key="ability.value" :variant="ability.sensitive ? 'warning' : 'neutral'" :title="ability.sensitive ? t.sensitive : undefined">
            {{ ability.label }}
          </Badge>
        </div>
      </template>

      <template #cell-issuer="{ row }">
        <span v-if="row.issuer === null" class="text-muted-foreground">{{ t.issuer_deleted }}</span>
        <span v-else :class="row.issuer.active ? '' : 'text-muted-foreground'">
          {{ row.issuer.name }}<template v-if="!row.issuer.active"> ({{ t.issuer_inactive }})</template>
        </span>
      </template>

      <template #cell-expires_at="{ row }">
        <span class="text-muted-foreground" :title="row.expires_at">{{ formatDate(row.expires_at) }}</span>
      </template>

      <template #cell-last_used_at="{ row }">
        <span class="text-muted-foreground" :title="row.last_used_at ?? undefined">{{ row.last_used_at ? formatDate(row.last_used_at) : t.never_used }}</span>
      </template>

      <template #cell-status="{ row }">
        <StatusDot :tone="TONES[row.status]">{{ t.statuses[row.status] }}</StatusDot>
      </template>

      <template #actions="{ row }">
        <div v-if="row.status !== 'revoked'" class="flex justify-end gap-1">
          <Button v-if="row.status === 'active'" variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions.rotate, { name: row.name })" @click="rotate(row)">
            <RefreshCw aria-hidden="true" />
          </Button>
          <Button variant="ghost" size="icon-sm" :aria-label="interpolate(t.actions.revoke, { name: row.name })" @click="askToRevoke(row)">
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </template>

      <template #empty="{ searching }">
        <EmptyState v-if="searching" :icon="SearchX" :title="tableLabels.empty_search" />
        <EmptyState v-else :title="t.empty" :description="t.empty_hint" />
      </template>
    </DataTable>

    <TokenFormDialog v-model:open="formOpen" :row="rowToRotate" :grantable="grantable" :lifetime="lifetime" :store-url="urls.store" />

    <ConfirmDialog
      v-model:open="revokeOpen"
      :title="t.revoke.title"
      :description="interpolate(t.revoke.description, { name: rowToRevoke?.name ?? '' })"
      :confirm-label="t.revoke.confirm"
      :cancel-label="common.cancel"
      @confirm="revoke"
    />

    <RevealDialog />
  </div>
</template>
