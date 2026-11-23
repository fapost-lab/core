<script setup lang="ts">
import {computed} from 'vue'
import {Handle, Position} from '@vue-flow/core'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useConfirm} from '@builder/composables/useConfirm'
import {useTranslations} from '@builder/composables/useTranslations'
import {nodeColors} from '@builder/utils/nodeColors'
import {fallbackTypeLabel, isNodeUnavailable, nodeAvailability} from '@builder/utils/nodeAvailability'
import GraphInsertButton from './GraphInsertButton.vue'
import {GRAPH_CARD_HEAD, GRAPH_CARD_HEIGHT, GRAPH_CARD_WIDTH, GRAPH_HANDLE_ROW, loopBodySize, type GraphNodeBox} from './definitionGraph'

/**
 * One node on the builder's graph: the tree card's head (icon, colours, handler label) with the node's label, and a
 * "+" for each of its handles that leads nowhere yet. A click selects the node for the shared ConfigPanel; delete and
 * move open the same dialogs the tree does. A read-only draft draws no control that changes it.
 */
const props = defineProps<{
    data: { box: GraphNodeBox }
}>()

const {t} = useTranslations()
const builderStore = useBuilderStore()
const registryStore = useRegistryStore()
const selectionStore = useSelectionStore()
const deleteDialog = useDeleteNode()
const moveDialog = useMoveNode()
const {confirm} = useConfirm()

const node = computed(() => props.data.box.node)
const colors = computed(() => nodeColors(node.value.type))
const handler = computed(() => registryStore.getByType(node.value.type, node.value.version))
const typeLabel = computed(() => handler.value?.label ?? fallbackTypeLabel(node.value.type))
const unavailable = computed(() => isNodeUnavailable(nodeAvailability(node.value.type, handler.value)))
const isSelected = computed(() => selectionStore.selectedNodeId === node.value.id)
const hasError = computed(() => builderStore.nodesWithErrors.has(node.value.id))
const editable = computed(() => !builderStore.readOnly)

/** loop_end lives and dies with its loop, as in the tree: it is neither deleted nor moved on its own. */
const isManaged = computed(() => node.value.type === 'loop_end')

/** A subflow card names the flow it starts and links to it; the child flow is another draft, never drawn inside. */
const subflowId = computed<string | null>(() => {
    if (node.value.type !== 'subflow') {
        return null
    }
    const id = (node.value.config ?? {}).flow_id

    return typeof id === 'string' && id !== '' ? id : null
})
const subflowName = computed(() => builderStore.availableFlows.find((flow) => flow.id === subflowId.value)?.name ?? null)

const caption = computed<string | null>(() => {
    if (node.value.type === 'subflow') {
        return subflowName.value ?? (subflowId.value === null ? t('graph.no_flow') : null)
    }

    return node.value.label ?? null
})

function select(): void {
    selectionStore.toggle(node.value.id)
}

/** A loop goes with its whole body, so one with content is deleted only after a yes — as from the tree's loop card. */
async function requestDelete(): Promise<void> {
    const bodySize = node.value.type === 'loop' ? loopBodySize(builderStore.definition, node.value.id) : 0

    if (bodySize > 0) {
        const ok = await confirm({
            title:        t('graph.delete_loop_title'),
            message:      t('graph.delete_loop_message').replace(':count', String(bodySize)),
            confirmLabel: t('graph.delete_loop_ok'),
            cancelLabel:  t('graph.delete_loop_cancel'),
            danger:       true,
        })
        if (!ok) {
            return
        }
    }

    deleteDialog.requestDelete(node.value.id)
}
</script>

<template>
    <div
        class="node-card graph-card"
        :class="{
            selected: isSelected,
            'has-error': hasError && !isSelected,
            'node-card--unavailable': unavailable,
        }"
        :style="{ width: `${GRAPH_CARD_WIDTH}px`, height: `${data.box.height}px` }"
        :data-node-id="node.id"
        @click.stop="select"
    >
        <Handle type="target" :position="Position.Top" class="graph-card-anchor" />
        <div class="node-card-head graph-card-head" :style="{ background: colors.bg, color: colors.color, height: `${GRAPH_CARD_HEAD}px` }">
            <div class="node-type-icon"><NodeIcon :type="node.type" /></div>
            <span class="graph-card-type">{{ typeLabel }}</span>
            <span v-if="hasError" class="graph-card-warn" :title="t('graph.validation')">!</span>
            <template v-if="editable && !isManaged">
                <button
                    type="button"
                    class="node-delete-btn graph-card-action nodrag nopan"
                    :title="t('graph.move')"
                    :aria-label="t('graph.move')"
                    @click.stop="moveDialog.open(node.id)"
                >⇆</button>
                <button
                    type="button"
                    class="node-delete-btn nodrag nopan"
                    :title="t('graph.delete')"
                    :aria-label="t('graph.delete')"
                    @click.stop="requestDelete"
                >×</button>
            </template>
        </div>
        <div class="graph-card-body" :style="{ height: `${GRAPH_CARD_HEIGHT - GRAPH_CARD_HEAD}px` }">
            <span class="graph-card-caption" :class="{ 'graph-card-caption--muted': caption === null }">{{ caption ?? node.id }}</span>
            <a
                v-if="subflowId !== null"
                class="graph-card-link nodrag nopan"
                :href="`/builder/flows/${subflowId}`"
                @click.stop
            >{{ t('graph.open_flow') }} ↗</a>
        </div>
        <div v-if="editable && data.box.freeHandles.length > 0" class="graph-card-handles">
            <div
                v-for="handle in data.box.freeHandles"
                :key="handle.handle"
                class="graph-card-handle"
                :style="{ height: `${GRAPH_HANDLE_ROW}px` }"
            >
                <GraphInsertButton
                    :after-node-id="node.id"
                    :handle="handle.handle"
                    :label="handle.label"
                    :title="t('graph.add_after').replace(':handle', handle.label)"
                />
            </div>
        </div>
        <Handle type="source" :position="Position.Bottom" class="graph-card-anchor" />
    </div>
</template>

<style scoped>
.graph-card {
    max-width: none;
    display: flex;
    flex-direction: column;
}
.graph-card-head {
    flex-shrink: 0;
}
.graph-card-type {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    font-weight: 600;
}
.graph-card-warn {
    width: 16px;
    height: 16px;
    border-radius: 999px;
    background: var(--rose);
    color: var(--on-solid);
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.graph-card-action {
    font-size: 13px;
}
.graph-card-body {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 0 12px;
    min-width: 0;
    flex-shrink: 0;
}
.graph-card-caption {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
    color: var(--text);
}
.graph-card-caption--muted {
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--text-3);
}
.graph-card-link {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
}
.graph-card-link:hover { text-decoration: underline; }
.graph-card-handles {
    display: flex;
    flex-direction: column;
    padding: 0 12px;
}
.graph-card-handle {
    display: flex;
    align-items: center;
    min-width: 0;
}
.graph-card-anchor {
    opacity: 0;
    pointer-events: none;
}
</style>
