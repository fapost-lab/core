<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { interpolate } from '@console/shell'
import type { DrawnFrame, RunGraphTranslations } from './runGraph'

/** A frame of the run graph: a branch of a node, or the subflow run a node started, drawn around its nodes. */
const props = defineProps<{
  data: { drawn: DrawnFrame; labels: RunGraphTranslations; width: number; height: number }
}>()

const title = computed(() => {
  const frame = props.data.drawn
  const labels = props.data.labels.frames

  if (frame.frame === 'subflow') {
    const name = interpolate(labels.subflow, { name: frame.title ?? labels.unnamed })

    return frame.count > 1 ? `${name} · ${interpolate(labels.runs, { count: frame.count })}` : name
  }

  // A rule's or a button's handle is its id: a long one is shortened, the way the session list shortens ids.
  const handle = frame.title ?? ''

  return interpolate(labels.branch, { handle: handle.length > 12 ? handle.slice(0, 8) : handle })
})
</script>

<template>
  <div
    class="rounded-xl border"
    :class="[
      data.drawn.frame === 'subflow' ? 'bg-muted/40 border-solid' : 'border-dashed',
      data.drawn.status === 'untaken' ? 'opacity-50' : '',
    ]"
    :style="{ width: `${data.width}px`, height: `${data.height}px` }"
  >
    <Handle type="target" :position="Position.Top" class="!opacity-0" />
    <div class="text-muted-foreground truncate px-3 py-1.5 text-xs font-medium">{{ title }}</div>
  </div>
</template>
