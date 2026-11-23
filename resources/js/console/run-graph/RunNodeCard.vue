<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { StatusDot } from '@fapost/ui/components/badge'
import NodeIcon from '@builder/components/NodeIcon.vue'
import { nodeColors } from '@builder/utils/nodeColors'
import { fallbackTypeLabel } from '@builder/utils/nodeAvailability'
import { interpolate } from '@console/shell'
import { CARD_HEIGHT, CARD_WIDTH } from './runGraphLayout'
import { RUN_STATUS_TONES, type DrawnNode, type RunGraphTranslations } from './runGraph'

/**
 * One node of the run graph: the builder card's head (its icon, colours and handler label) with the node's label and
 * what the run did there. Read-only; a click opens the node's visits (Graph.vue).
 */
const props = defineProps<{
  data: { drawn: DrawnNode; labels: RunGraphTranslations; selected: boolean }
}>()

const node = computed(() => props.data.drawn.node)
const colors = computed(() => nodeColors(node.value.type))
const typeLabel = computed(() => node.value.typeLabel ?? fallbackTypeLabel(node.value.type))
const status = computed(() => props.data.drawn.status)
const loop = computed(() => props.data.drawn.loop)
const visits = computed(() => node.value.run?.visits ?? 0)
</script>

<template>
  <div
    class="bg-card text-card-foreground flex flex-col overflow-hidden rounded-lg border shadow-xs transition-opacity"
    :class="[
      status === 'untaken' ? 'border-dashed opacity-45' : '',
      status === 'failed' ? 'border-destructive ring-destructive/20 ring-2' : '',
      status === 'running' || status === 'waiting' ? 'ring-primary/25 ring-2' : '',
      data.selected ? 'ring-primary ring-2' : '',
    ]"
    :style="{ width: `${CARD_WIDTH}px`, height: `${CARD_HEIGHT}px` }"
    :data-status="status"
  >
    <Handle type="target" :position="Position.Top" class="!opacity-0" />
    <div class="flex items-center gap-2 px-3 py-1.5 text-xs font-semibold" :style="{ background: colors.bg, color: colors.color }">
      <span class="size-4 shrink-0"><NodeIcon :type="node.type" /></span>
      <span class="truncate">{{ typeLabel }}</span>
      <span v-if="loop" class="ml-auto shrink-0 tabular-nums">{{ interpolate(data.labels.loop.times, { count: loop.count }) }}</span>
      <span v-else-if="data.drawn.pass === null && visits > 1" class="ml-auto shrink-0 tabular-nums">{{ interpolate(data.labels.loop.times, { count: visits }) }}</span>
    </div>
    <div class="flex min-w-0 flex-1 items-center justify-between gap-2 px-3">
      <span class="truncate text-sm" :class="node.label ? '' : 'text-muted-foreground font-mono text-xs'">{{ node.label ?? node.id }}</span>
      <StatusDot :tone="RUN_STATUS_TONES[status]" class="shrink-0">{{ data.labels.statuses[status] }}</StatusDot>
    </div>
    <Handle type="source" :position="Position.Bottom" class="!opacity-0" />
  </div>
</template>
