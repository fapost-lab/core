<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Plus } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import type { TableDefaults, TableMeta, TableState } from '@fapost/ui/components/data-table'
import ChannelsTable from './ChannelsTable.vue'
import type { ChannelRow, ChannelsPageProps } from './types'

defineProps<{
  table: { rows: ChannelRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
  limit: { reached: boolean; hint: string | null }
  can: { create: boolean; update: boolean; delete: boolean; rotate: boolean }
  urls: { index: string; create: string }
}>()

const page = usePage<ChannelsPageProps>()
const t = computed(() => page.props.translations.console.channels)
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground">{{ t.description }}</p>
      <p v-if="limit.hint" class="text-destructive text-sm font-medium" role="status">{{ limit.hint }}</p>
    </div>

    <ChannelsTable :table="table" :can="can" :url="urls.index">
      <template #toolbar>
        <Button v-if="can.create" as-child class="ml-auto">
          <Link :href="urls.create">
            <Plus aria-hidden="true" />
            {{ t.new }}
          </Link>
        </Button>
      </template>
    </ChannelsTable>
  </div>
</template>
