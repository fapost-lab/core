<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue'
import { VueFlow, type Edge, type Node, type NodeMouseEvent, type VueFlowStore } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '../../../css/node-palette.css'
import RunFrame from './RunFrame.vue'
import RunNodeCard from './RunNodeCard.vue'
import { drawRunGraph, type DrawnFrame, type DrawnNode, type PassSelection, type RunGraph, type RunGraphTranslations } from './runGraph'
import { layoutRunGraph, type PlacedBox } from './runGraphLayout'

/**
 * The run graph on a canvas: ELK lays the run's structure out, Vue Flow draws it with the run's statuses for the chosen
 * loop passes. A lazy chunk of its own (Graph.vue loads it asynchronously): Vue Flow and ELK are large.
 */
const props = defineProps<{
  graph: RunGraph
  selection: PassSelection
  labels: RunGraphTranslations
  selectedId: string | null
}>()

const emit = defineEmits<{ select: [node: DrawnNode] }>()

// Layout depends on the structure only, so it is redone when the graph changes, not when a pass is chosen.
const placed = shallowRef<PlacedBox[]>([])
const structureKey = computed(() => drawRunGraph(props.graph).boxes.map((box) => `${box.id}<${box.parent ?? ''}`).join('|'))
let layoutRun = 0

watch(
  structureKey,
  async () => {
    const run = ++layoutRun
    const boxes = await layoutRunGraph(drawRunGraph(props.graph))

    if (run === layoutRun) {
      placed.value = boxes
    }
  },
  { immediate: true },
)

const drawn = computed(() => drawRunGraph(props.graph, props.selection))
const drawnById = computed(() => new Map(drawn.value.boxes.map((box) => [box.id, box])))

const nodes = computed<Node[]>(() =>
  placed.value.map(({ box, position, width, height }) => {
    const current = drawnById.value.get(box.id) ?? box
    const isFrame = current.kind === 'frame'

    return {
      id: box.id,
      type: isFrame ? 'frame' : 'run',
      position,
      parentNode: box.parent ?? undefined,
      draggable: false,
      connectable: false,
      selectable: false,
      zIndex: isFrame ? 0 : 1,
      style: isFrame ? { width: `${width}px`, height: `${height}px` } : undefined,
      data: isFrame
        ? { drawn: current as DrawnFrame, labels: props.labels, width, height }
        : { drawn: current as DrawnNode, labels: props.labels, selected: props.selectedId === box.id },
    }
  }),
)

const edges = computed<Edge[]>(() =>
  drawn.value.edges.map((edge) => ({
    id: edge.id,
    source: edge.source,
    target: edge.target,
    type: 'smoothstep',
    zIndex: 1,
    style: edge.taken ? { stroke: 'var(--primary)', strokeWidth: 2 } : { stroke: 'var(--border)', strokeWidth: 1.5, strokeDasharray: '4 4' },
  })),
)

// The layout arrives after the canvas is ready: the view is fitted once the laid-out nodes are measured.
let canvas: VueFlowStore | null = null

function ready(instance: VueFlowStore): void {
  canvas = instance
}

function fit(): void {
  void canvas?.fitView({ padding: 0.1 })
}

function open(event: NodeMouseEvent): void {
  const box = drawnById.value.get(event.node.id)

  if (box !== undefined && box.kind === 'node') {
    emit('select', box)
  }
}
</script>

<template>
  <VueFlow
    :nodes="nodes"
    :edges="edges"
    :min-zoom="0.1"
    :max-zoom="1.5"
    :nodes-draggable="false"
    :nodes-connectable="false"
    :elements-selectable="false"
    @pane-ready="ready"
    @nodes-initialized="fit"
    @node-click="open"
  >
    <template #node-run="{ data }">
      <RunNodeCard :data="data" />
    </template>
    <template #node-frame="{ data }">
      <RunFrame :data="data" />
    </template>
  </VueFlow>
</template>
