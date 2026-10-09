<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import { cn } from '@fapost/ui/lib/utils'
import { Button } from '@fapost/ui/components/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { interpolate } from '@fapost/ui/shell'
import type { TableTranslations } from '@fapost/ui/shell'
import { pageWindow } from './query'
import type { TableMeta } from './query'

/** The footer of a data table: which rows are shown, the page size, and the previous and next page. */
const props = defineProps<{
  meta: TableMeta
  perPageOptions: readonly number[]
  labels: TableTranslations
  busy?: boolean
}>()

const emit = defineEmits<{
  page: [page: number]
  perPage: [perPage: number]
}>()

const range = computed(() =>
  props.meta.total === 0
    ? ''
    : interpolate(props.labels.range, { from: props.meta.from ?? 0, to: props.meta.to ?? 0, total: props.meta.total }),
)
const pageLabel = computed(() => interpolate(props.labels.page, { page: props.meta.currentPage, last: props.meta.lastPage }))
const pages = computed(() => pageWindow(props.meta.currentPage, props.meta.lastPage))
</script>

<template>
  <div class="text-muted-foreground border-border flex flex-wrap items-center gap-3 border-t px-3.5 py-3 text-[13px]">
    <span class="flex-1">{{ range }}</span>

    <div class="flex items-center gap-1.5">
      <span>{{ labels.rows_per_page }}</span>
      <Select :model-value="String(meta.perPage)" @update:model-value="(value) => emit('perPage', Number(value))">
        <SelectTrigger size="sm" class="w-[4.5rem]" :aria-label="labels.rows_per_page">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in perPageOptions" :key="option" :value="String(option)">{{ option }}</SelectItem>
        </SelectContent>
      </Select>
    </div>

    <nav class="flex items-center gap-1" :aria-label="pageLabel">
      <Button
        variant="outline"
        size="icon-sm"
        :aria-label="labels.previous"
        :disabled="busy || meta.currentPage <= 1"
        @click="emit('page', meta.currentPage - 1)"
      >
        <ChevronLeft aria-hidden="true" />
      </Button>
      <template v-for="(pageNumber, index) in pages" :key="`${index}:${pageNumber}`">
        <span v-if="pageNumber === null" class="w-6 text-center" aria-hidden="true">…</span>
        <Button
          v-else
          :variant="pageNumber === meta.currentPage ? 'default' : 'outline'"
          size="icon-sm"
          :class="cn('w-auto min-w-8 px-2 font-normal', pageNumber === meta.currentPage && 'font-semibold')"
          :aria-current="pageNumber === meta.currentPage ? 'page' : undefined"
          :disabled="busy"
          @click="pageNumber !== meta.currentPage && emit('page', pageNumber)"
        >
          {{ pageNumber }}
        </Button>
      </template>
      <Button
        variant="outline"
        size="icon-sm"
        :aria-label="labels.next"
        :disabled="busy || meta.currentPage >= meta.lastPage"
        @click="emit('page', meta.currentPage + 1)"
      >
        <ChevronRight aria-hidden="true" />
      </Button>
    </nav>
  </div>
</template>
