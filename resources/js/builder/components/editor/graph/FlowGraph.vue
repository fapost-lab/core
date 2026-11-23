<script setup lang="ts">
import {computed, shallowRef, watch} from 'vue'
import {VueFlow, type Edge, type Node, type VueFlowStore} from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useTranslations} from '@builder/composables/useTranslations'
import {nodeHandles} from '@builder/utils/nodeHandles'
import {layoutGraph, type PlacedBox} from '@builder/utils/graphLayout'
import GraphNodeCard from './GraphNodeCard.vue'
import GraphFrame from './GraphFrame.vue'
import GraphInsertEdge from './GraphInsertEdge.vue'
import GraphInsertButton from './GraphInsertButton.vue'
import GraphTriggers from './GraphTriggers.vue'
import {
    drawDefinitionGraph,
    GRAPH_CARD_HEIGHT,
    GRAPH_CARD_WIDTH,
    layoutKey,
    type GraphFrameBox,
    type GraphNodeBox,
} from './definitionGraph'

/**
 * The graph mode of the builder: the whole draft on one canvas, branches of conditions and buttons as nested frames,
 * laid out by ELK. It is a second view over the same `builderStore` — it never writes the definition itself: a "+"
 * runs `insertNode`, the card buttons open the tree's delete and move dialogs, and a click selects the node for the
 * shared ConfigPanel. Nothing is dragged and no position is stored; the layout follows the structure. A lazy chunk of
 * its own (FlowEditor loads it asynchronously): Vue Flow and ELK are large and the tree does not need them.
 */
const {t} = useTranslations()
const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()

const editable = computed(() => !builderStore.readOnly)

// A read-only draft offers no free handle, so its cards are drawn without the "+" rows.
const drawn = computed(() => drawDefinitionGraph(builderStore.definition, editable.value ? nodeHandles : () => []))

const placed = shallowRef<PlacedBox<GraphNodeBox | GraphFrameBox>[]>([])
let layoutRun = 0

// The layout depends on the structure only: editing a node's text keeps every card where it is.
watch(
    () => layoutKey(drawn.value),
    async () => {
        const run = ++layoutRun
        const boxes = await layoutGraph(drawn.value, {width: GRAPH_CARD_WIDTH, height: GRAPH_CARD_HEIGHT})

        if (run === layoutRun) {
            placed.value = boxes
        }
    },
    {immediate: true},
)

const drawnById = computed(() => new Map(drawn.value.boxes.map((box) => [box.id, box])))
const nodesById = computed(() => new Map(builderStore.definition.nodes.map((node) => [node.id, node])))

function frameTitle(frame: GraphFrameBox): string {
    const owner = nodesById.value.get(frame.nodeId)
    const label = owner ? nodeHandles(owner).find((handle) => handle.handle === frame.handle)?.label : undefined

    return t('graph.branch').replace(':handle', label ?? frame.handle)
}

const nodes = computed<Node[]>(() =>
    placed.value.flatMap(({box, position, width, height}) => {
        // A box the latest edit removed waits for the next layout to go; one it changed is drawn from the latest drawing.
        const current = drawnById.value.get(box.id)
        if (current === undefined || (box.parent !== null && !drawnById.value.has(box.parent))) {
            return []
        }

        const isFrame = current.kind === 'frame'

        return [{
            id: box.id,
            type: isFrame ? 'frame' : 'card',
            position,
            parentNode: box.parent ?? undefined,
            draggable: false,
            connectable: false,
            selectable: false,
            zIndex: isFrame ? 0 : 1,
            style: isFrame ? {width: `${width}px`, height: `${height}px`} : undefined,
            data: isFrame
                ? {title: frameTitle(current as GraphFrameBox), width, height}
                : {box: current as GraphNodeBox},
        }]
    }),
)

// An edge waits for the layout that places both of its nodes (a node just added has none yet).
const edges = computed<Edge[]>(() => {
    const shown = new Set(nodes.value.map((node) => node.id))

    return drawn.value.edges.filter((edge) => shown.has(edge.source) && shown.has(edge.target)).map((edge) => ({
        id: edge.id,
        source: edge.source,
        target: edge.target,
        type: 'insert',
        zIndex: 1,
        style: {stroke: 'var(--border-2)', strokeWidth: 1.5},
        data: {handle: edge.handle, editable: editable.value, title: t('graph.add_block')},
    }))
})

const isEmpty = computed(() => builderStore.definition.nodes.length === 0)

// The view is fitted once, on the first layout: later edits keep the author's zoom and position.
let canvas: VueFlowStore | null = null
let fitted = false

function ready(instance: VueFlowStore): void {
    canvas = instance
}

function fitOnce(): void {
    if (fitted || canvas === null || placed.value.length === 0) {
        return
    }
    fitted = true
    void canvas.fitView({padding: 0.15, maxZoom: 1})
}

function clearSelection(): void {
    selectionStore.clear()
}
</script>

<template>
    <div class="panel-graph">
        <GraphTriggers v-if="!builderStore.isTemplate" />
        <div v-if="isEmpty && editable" class="graph-empty">
            <GraphInsertButton :title="t('graph.add_first')" :label="t('graph.add_first')" />
        </div>
        <VueFlow
            class="graph-canvas"
            :nodes="nodes"
            :edges="edges"
            :min-zoom="0.1"
            :max-zoom="1.5"
            :nodes-draggable="false"
            :nodes-connectable="false"
            :elements-selectable="false"
            :zoom-on-double-click="false"
            @pane-ready="ready"
            @nodes-initialized="fitOnce"
            @pane-click="clearSelection"
        >
            <template #node-card="{ data }">
                <GraphNodeCard :data="data" />
            </template>
            <template #node-frame="{ data }">
                <GraphFrame :data="data" />
            </template>
            <template #edge-insert="edgeProps">
                <GraphInsertEdge v-bind="edgeProps" />
            </template>
        </VueFlow>
    </div>
</template>

<style scoped>
.panel-graph {
    position: relative;
    flex: 1;
    min-width: 0;
    min-height: 0;
    background: var(--bg);
}
.graph-canvas {
    width: 100%;
    height: 100%;
}
.graph-empty {
    position: absolute;
    inset: 0;
    z-index: 4;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}
.graph-empty > * { pointer-events: all; }
</style>
