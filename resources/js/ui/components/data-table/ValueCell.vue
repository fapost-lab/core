<script setup lang="ts">
import { computed } from 'vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { formatCell, type CellTone, type CellValue } from './cells'

/**
 * A table cell drawn from its type alone (`cells.ts`): a dash when empty, text, a soft badge or a status dot.
 */
const props = defineProps<{
  type: string
  value: CellValue
  locale: string
  tones?: Partial<Record<string, CellTone>>
}>()

const cell = computed(() => formatCell(props.type, props.value, { locale: props.locale, tones: props.tones }))
</script>

<template>
  <span v-if="cell.kind === 'empty'" class="text-muted-foreground" data-cell="empty">—</span>
  <Badge v-else-if="cell.kind === 'badge'" :variant="cell.tone" data-cell="badge">{{ cell.text }}</Badge>
  <StatusDot v-else-if="cell.kind === 'status'" :tone="cell.tone" data-cell="status">{{ cell.text }}</StatusDot>
  <span v-else-if="cell.numeric" class="block text-right tabular-nums" data-cell="number">{{ cell.text }}</span>
  <span v-else :class="cell.title ? 'text-muted-foreground' : undefined" :title="cell.title" data-cell="text">{{ cell.text }}</span>
</template>
