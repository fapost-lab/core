<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { interpolate } from '@fapost/ui/shell'
import type { TableTranslations } from '@fapost/ui/shell'
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
</script>

<template>
  <div class="text-muted-foreground flex flex-wrap items-center justify-between gap-3 text-sm">
    <span>{{ range }}</span>

    <div class="flex flex-wrap items-center gap-3">
      <div class="flex items-center gap-2">
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

      <span>{{ pageLabel }}</span>

      <div class="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon-sm"
          :aria-label="labels.previous"
          :disabled="busy || meta.currentPage <= 1"
          @click="emit('page', meta.currentPage - 1)"
        >
          <ChevronLeft aria-hidden="true" />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          :aria-label="labels.next"
          :disabled="busy || meta.currentPage >= meta.lastPage"
          @click="emit('page', meta.currentPage + 1)"
        >
          <ChevronRight aria-hidden="true" />
        </Button>
      </div>
    </div>
  </div>
</template>
