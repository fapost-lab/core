<script lang="ts" setup>
import {computed} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {type FlowNodeLike, nodeHandles} from '@builder/utils/nodeHandles'
import BaseModal from '@builder/components/editor/config/overrides/BaseModal.vue'

/**
 * Delete a node that owns branches.
 *
 * The store refuses to drop such a node on its own — its branch chains would be
 * stranded — so the author decides what happens to them first: move a chain
 * somewhere else (the existing Move dialog, chain mode), or delete the whole
 * construct at once.
 */
const props = defineProps({
    open:   {type: Boolean, required: true},
    nodeId: {type: String as () => string | null, default: null},
})

const emit = defineEmits(['close'])

const builderStore  = useBuilderStore()
const registryStore = useRegistryStore()
const moveDialog    = useMoveNode()

interface BranchSummary {
    handle:      string
    handleLabel: string
    headId:      string
    headLabel:   string
    count:       number
}

const node = computed<FlowNodeLike | null>(() => {
    if (!props.nodeId) return null
    const found = builderStore.definition.nodes.find((n) => n.id === props.nodeId)
    return found ? (found as unknown as FlowNodeLike) : null
})

const nodeLabel = computed<string>(() => (node.value ? labelFor(node.value) : ''))

/** One row per wired branch, with the size of the chain hanging off it. */
const branches = computed<BranchSummary[]>(() => {
    const current = node.value
    if (!current) return []

    const handleLabels = new Map(nodeHandles(current).map((h) => [h.handle, h.label]))

    return builderStore.definition.edges
        .filter((edge) => edge.from === current.id && (edge.handle ?? 'default') !== 'default')
        .map((edge) => {
            const head = builderStore.definition.nodes.find((n) => n.id === edge.to)
            const handle = edge.handle ?? 'default'

            return {
                handle,
                handleLabel: handleLabels.get(handle) ?? handle,
                headId:      edge.to,
                headLabel:   head ? labelFor(head as unknown as FlowNodeLike) : edge.to,
                count:       chainSize(edge.to),
            }
        })
})

const totalNodes = computed<number>(() =>
    props.nodeId ? builderStore.collectBranchDescendantIds(props.nodeId).length : 0,
)

/** Nodes reachable from a branch head, the head itself included. */
function chainSize(headId: string): number {
    const seen  = new Set<string>([headId])
    const stack = [headId]

    while (stack.length > 0) {
        const current = stack.pop()!
        for (const edge of builderStore.definition.edges) {
            if (edge.from !== current || seen.has(edge.to)) continue
            seen.add(edge.to)
            stack.push(edge.to)
        }
    }

    return seen.size
}

function labelFor(target: FlowNodeLike): string {
    const meta = registryStore.getByType(target.type, target.version ?? 1)
    if (meta?.label) return meta.label as string
    return target.type.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase())
}

/** Hand the branch to the Move dialog in chain mode, then step aside. */
function moveBranch(branch: BranchSummary) {
    emit('close')
    moveDialog.open(branch.headId, {chain: true})
}

function deleteEverything() {
    if (props.nodeId) {
        builderStore.deleteNodeWithBranches(props.nodeId)
    }
    emit('close')
}
</script>

<template>
    <BaseModal
        :open="open"
        :title="nodeLabel ? `Delete ${nodeLabel}?` : 'Delete node?'"
        width="520px"
        @close="emit('close')"
    >
        <div class="delete-dialog">
            <p class="delete-intro">
                This node holds {{ branches.length }}
                branch{{ branches.length > 1 ? 'es' : '' }} with {{ totalNodes }}
                node{{ totalNodes > 1 ? 's' : '' }}. Move a branch out to keep it, or delete everything together.
            </p>

            <ul class="delete-branches">
                <li v-for="branch in branches" :key="branch.handle" class="delete-branch">
                    <div class="delete-branch-main">
                        <span class="delete-branch-handle">{{ branch.handleLabel }}</span>
                        <span class="delete-branch-arrow">→</span>
                        <span class="delete-branch-head">{{ branch.headLabel }}</span>
                        <span class="delete-branch-count">
                            {{ branch.count }} node{{ branch.count > 1 ? 's' : '' }}
                        </span>
                    </div>
                    <button class="delete-branch-move" type="button" @click="moveBranch(branch)">
                        Move out…
                    </button>
                </li>
            </ul>

            <div class="delete-actions">
                <button class="delete-btn delete-btn--ghost" type="button" @click="emit('close')">
                    Cancel
                </button>
                <button class="delete-btn delete-btn--danger" type="button" @click="deleteEverything">
                    Delete with branches
                </button>
            </div>
        </div>
    </BaseModal>
</template>

<style scoped>
.delete-dialog {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.delete-intro {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.45;
    color: var(--text-2);
}

.delete-branches {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 260px;
    overflow-y: auto;
}

.delete-branch {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
}

.delete-branch-main {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 0;
    font-size: 12.5px;
}

.delete-branch-handle {
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.delete-branch-arrow,
.delete-branch-count {
    color: var(--text-3);
    flex-shrink: 0;
}

.delete-branch-head {
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.delete-branch-move {
    flex-shrink: 0;
    border: 1px solid var(--border);
    background: transparent;
    border-radius: 5px;
    padding: 4px 8px;
    font-size: 11.5px;
    color: var(--text-2);
    cursor: pointer;
}

.delete-branch-move:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.delete-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding-top: 4px;
}

.delete-btn {
    border-radius: 6px;
    padding: 7px 14px;
    font-size: 12.5px;
    cursor: pointer;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-2);
}

.delete-btn--ghost:hover {
    color: var(--text);
}

.delete-btn--danger {
    border-color: var(--rose);
    background: var(--rose);
    color: var(--on-solid);
}

.delete-btn--danger:hover {
    filter: brightness(1.05);
}
</style>
