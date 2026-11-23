<script setup lang="ts">
import {computed, ref} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {countDescendants, type TreeNode} from '@builder/utils/buildTree'
import {useTranslations} from '@builder/composables/useTranslations'

const HANDLES = [
    {handle: 'started',    cls: 'branch-yes'},
    {handle: 'busy',       cls: 'branch-no'},
    {handle: 'no_channel', cls: 'branch-no'},
    {handle: 'error',      cls: 'branch-no'},
] as const

const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const { t } = useTranslations()
const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

const node       = computed(() => props.treeNode.node)
const config     = computed(() => node.value.config ?? {})
const colors     = computed(() => nodeColors('start_flow'))
const isSelected = computed(() => selectionStore.selectedNodeId === node.value.id)
const hasError   = computed(() => builderStore.nodesWithErrors.has(node.value.id))

// Target flow: its name when the builder knows it, otherwise the raw id.
const flowId   = computed(() => (config.value.flow_id as string | undefined) || '')
const flowName = computed(() => {
    if (!flowId.value) return null
    return builderStore.availableFlows.find(f => f.id === flowId.value)?.name ?? flowId.value
})

// Computed so the labels follow the translations of the page.
const handles = computed(() => HANDLES.map((h) => ({...h, label: t(`nodes.start_flow.handle_${h.handle}`)})))

const activeHandle = ref('')

function selectCard() {
    selectionStore.toggle(node.value.id)
}

function selectBranch(handle: string) {
    activeHandle.value = handle
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), node.value.id, handle])
}

function childCount(handle: string): number {
    const children: TreeNode[] = (props.treeNode.childrenByHandle?.[handle] ?? []) as TreeNode[]
    return children.reduce((sum: number, child: TreeNode) => sum + 1 + countDescendants(child), 0)
}

const canMoveUp = computed(() => {
    if (nodeMustBeLast(node.value)) return false
    const incoming = builderStore.definition.edges.find((edge) => edge.to === node.value.id)
    if (!incoming) return false
    return (incoming.handle ?? 'default') === 'default'
})

const canMoveDown = computed(() => {
    const id = node.value.id
    const out = builderStore.definition.edges.find(
        (edge) => edge.from === id && (edge.handle ?? 'default') === 'default',
    )
    if (!out) return false
    const successor = builderStore.definition.nodes.find((n) => n.id === out.to)
    return !(successor && nodeMustBeLast(successor))
})

const moveDialog = useMoveNode()
const deleteDialog = useDeleteNode()
function openMoveDialog() { moveDialog.open(node.value.id) }
</script>

<template>
    <div class="node-card-wrap">
        <div
            :id="`node-card-${node.id}`"
            class="node-card"
            :class="{ selected: isSelected, 'has-error': hasError && !isSelected }"
            @click="selectCard"
        >
            <div class="node-card-head" :style="{ background: colors.bg, color: colors.color }">
                <div class="node-type-icon"><NodeIcon type="start_flow" /></div>
                <span class="node-type-label">{{ t('nodes.start_flow.label') }}</span>
                <div v-if="hasError" class="node-warn" :title="t('node_card.validation_error')">!</div>
                <span v-if="index != null" class="node-num">#{{ index }}</span>
                <button class="node-delete-btn" :title="t('node_card.delete')" @click.stop="deleteDialog.requestDelete(node.id)">×</button>
            </div>

            <div class="node-card-body">
                <div v-if="flowName" class="node-summary-row">
                    <span class="node-summary-key">{{ t('nodes.start_flow.section_target') }}</span>
                    <span class="node-summary-val">{{ flowName }}</span>
                </div>
                <div v-else class="node-summary-row">
                    <span class="node-summary-val muted">{{ t('nodes.start_flow.no_flow') }}</span>
                </div>

                <div class="branch-hint">{{ t('node_card.wire_hint') }}</div>
                <div class="condition-branches">
                    <button
                        v-for="h in handles"
                        :key="h.handle"
                        class="branch-btn"
                        :class="[h.cls, { 'active-branch': activeHandle === h.handle }]"
                        :title="t('node_card.open_branch').replace(':name', h.label)"
                        @click.stop="selectBranch(h.handle)"
                    >
                        {{ h.label }}
                        <span v-if="childCount(h.handle) > 0" class="branch-count">{{ childCount(h.handle) }}</span>
                        <span class="branch-chevron" aria-hidden="true">›</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="node-side-actions" @click.stop>
            <button class="node-side-btn" :title="t('node_card.move_up')" :disabled="!canMoveUp"   @click.stop="builderStore.moveNodeUp(node.id)">↑</button>
            <button class="node-side-btn" :title="t('node_card.move_down')" :disabled="!canMoveDown" @click.stop="builderStore.moveNodeDown(node.id)">↓</button>
            <button class="node-side-btn" :title="t('node_card.move_branch')" @click.stop="openMoveDialog">⇆</button>
        </div>
    </div>
</template>
