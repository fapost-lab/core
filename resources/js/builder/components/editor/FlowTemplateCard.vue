<script setup lang="ts">
import {computed, ref} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
import {nodeHandles} from '@builder/utils/nodeHandles'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import {useTranslations} from '@builder/composables/useTranslations'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {countDescendants, type TreeNode} from '@builder/utils/buildTree'

const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const {t} = useTranslations()
const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

const node       = computed(() => props.treeNode.node)
const colors     = computed(() => nodeColors('template'))
const isSelected = computed(() => selectionStore.selectedNodeId === node.value.id)
const hasError   = computed(() => builderStore.nodesWithErrors.has(node.value.id))

const templateId = computed(() => (node.value.config?.template_flow_id as string | undefined) ?? '')
const template   = computed(() => builderStore.availableTemplates.find((tpl) => tpl.id === templateId.value) ?? null)
// A template that was picked but is no longer offered: deleted, unpublished or of another assistant.
const isMissing  = computed(() => templateId.value !== '' && template.value === null)
const handles    = computed(() => nodeHandles(node.value))

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
                <div class="node-type-icon"><NodeIcon type="template" /></div>
                <span class="node-type-label">{{ t('nodes.template.type_label') }}</span>
                <div v-if="hasError" class="node-warn" :title="t('nodes.template.validation_error')">!</div>
                <span v-if="index != null" class="node-num">#{{ index }}</span>
                <button class="node-delete-btn" :title="t('nodes.template.delete')" @click.stop="deleteDialog.requestDelete(node.id)">×</button>
            </div>

            <div class="node-card-body">
                <div v-if="template" class="node-summary-row">
                    <span class="node-summary-key">{{ t('nodes.template.template') }}</span>
                    <span class="node-summary-val">{{ template.name }}</span>
                    <span class="node-summary-val muted">v{{ template.version }}</span>
                </div>
                <div v-else-if="isMissing" class="template-warning" role="alert">
                    {{ t('nodes.template.missing') }}
                </div>
                <div v-else class="node-summary-row">
                    <span class="node-summary-val muted">{{ t('nodes.template.not_selected') }}</span>
                </div>

                <template v-if="handles.length > 0">
                    <div class="branch-hint">{{ t('nodes.template.wire_hint') }}</div>
                    <div class="condition-branches">
                        <button
                            v-for="h in handles"
                            :key="h.handle"
                            class="branch-btn branch-default"
                            :class="{ 'active-branch': activeHandle === h.handle }"
                            :title="t('nodes.template.open_output').replace(':name', h.label)"
                            @click.stop="selectBranch(h.handle)"
                        >
                            {{ h.label }}
                            <span v-if="childCount(h.handle) > 0" class="branch-count">{{ childCount(h.handle) }}</span>
                            <span class="branch-chevron" aria-hidden="true">›</span>
                        </button>
                    </div>
                </template>

                <a
                    v-if="templateId !== ''"
                    class="template-open"
                    :href="`/builder/flows/${templateId}`"
                    @click.stop
                >{{ t('nodes.template.open') }} ↗</a>
            </div>
        </div>

        <div class="node-side-actions" @click.stop>
            <button class="node-side-btn" :title="t('nodes.template.move_up')"   :disabled="!canMoveUp"   @click.stop="builderStore.moveNodeUp(node.id)">↑</button>
            <button class="node-side-btn" :title="t('nodes.template.move_down')" :disabled="!canMoveDown" @click.stop="builderStore.moveNodeDown(node.id)">↓</button>
            <button class="node-side-btn" :title="t('nodes.template.move_branch')" @click.stop="openMoveDialog">⇆</button>
        </div>
    </div>
</template>

<style scoped>
.template-warning {
    padding: 6px 8px;
    border-radius: var(--radius);
    background: var(--amber-bg);
    color: var(--amber);
    font-size: 12px;
}
.template-open {
    display: inline-block;
    margin-top: 8px;
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
}
.template-open:hover { text-decoration: underline; }
</style>
