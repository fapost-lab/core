<script setup lang="ts">
import {computed, ref} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useNavigationStore} from '@builder/store/navigationStore'
import {useConfirm} from '@builder/composables/useConfirm'
import FlowNodeCard from './FlowNodeCard.vue'
import FlowInsertPoint from './FlowInsertPoint.vue'
import FlowConditionCard from './FlowConditionCard.vue'
import FlowSendMessageCard from './FlowSendMessageCard.vue'

interface TreeNode {
    node: { id: string; type: string; label?: string; config?: Record<string, unknown> }
    childrenByHandle?: Record<string, TreeNode[]>
}

const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()
const { confirm } = useConfirm()
const navigationStore = useNavigationStore()

function isTerminalSendMessage(node: TreeNode['node']): boolean {
    if (node.type !== 'send_message') return false
    const config = (node.config ?? {}) as Record<string, unknown>
    if (config.content_type !== 'text_with_keyboard') return false
    if ((config.keyboard_mode ?? 'inline') === 'reply') return true
    const buttons = Array.isArray(config.buttons) ? (config.buttons as unknown[]) : []
    return buttons.length > 0
}

// Walk the linear `default` chain at the current branch level. Stop as
// soon as we hit a terminal node — anything past it (default-children,
// sibling roots) is unreachable and surfaces in the orphan block below.
function flattenLinear(nodes: TreeNode[]): TreeNode[] {
    const result: TreeNode[] = []
    for (const node of nodes ?? []) {
        result.push(node)
        if (isTerminalSendMessage(node.node)) return result
        const nextDefault = node.childrenByHandle?.default ?? []
        if (nextDefault.length > 0) {
            result.push(...flattenLinear(nextDefault))
        }
    }
    return result
}

// Collect the linear `default`-chain past a terminal send_message.
function collectDefaultTail(terminal: TreeNode): TreeNode[] {
    const result: TreeNode[] = []
    let cursor: TreeNode[] = (terminal.childrenByHandle?.default ?? []) as TreeNode[]
    while (cursor.length > 0) {
        const item = cursor[0]
        result.push(item)
        cursor = (item.childrenByHandle?.default ?? []) as TreeNode[]
    }
    return result
}

// Sibling roots in the branch level that aren't part of the main path
// (i.e., aren't in activeNodes). Each sibling root is itself the head
// of a linear chain — collect it and its `default` descendants.
function collectSiblingRoots(branchRoots: TreeNode[], visible: TreeNode[]): TreeNode[] {
    const visibleIds = new Set(visible.map((n) => n.node.id))
    const result: TreeNode[] = []
    for (const root of branchRoots) {
        if (visibleIds.has(root.node.id)) continue
        result.push(root)
        let cursor: TreeNode[] = (root.childrenByHandle?.default ?? []) as TreeNode[]
        while (cursor.length > 0) {
            const item = cursor[0]
            result.push(item)
            cursor = (item.childrenByHandle?.default ?? []) as TreeNode[]
        }
    }
    return result
}

function findNodeInTree(nodes: TreeNode[], targetNodeId: string): TreeNode | null {
    for (const treeNode of nodes ?? []) {
        if (treeNode.node.id === targetNodeId) return treeNode
        for (const handleChildren of Object.values(treeNode.childrenByHandle ?? {})) {
            const found: TreeNode | null = findNodeInTree(handleChildren, targetNodeId)
            if (found) return found
        }
    }
    return null
}

function resolveBranchRoots(tree: TreeNode[], activeBranch: string[]): TreeNode[] {
    let currentLevel: TreeNode[] = tree ?? []
    for (let i = 0; i < activeBranch.length; i += 2) {
        const nodeId = activeBranch[i]
        const handle = activeBranch[i + 1]
        if (!nodeId || !handle) break
        const parent = findNodeInTree(currentLevel, nodeId)
        if (!parent) break
        currentLevel = parent.childrenByHandle?.[handle] ?? []
    }
    return currentLevel
}

const branchRoots = computed(() => resolveBranchRoots(
    builderStore.tree,
    selectionStore.activeBranch,
))

const activeNodes = computed(() => flattenLinear(branchRoots.value))

const unreachableNodes = computed<TreeNode[]>(() => {
    const result: TreeNode[] = []
    const seen = new Set<string>()

    // 1. Default-tail past a terminal send_message in the main path.
    const last = activeNodes.value[activeNodes.value.length - 1]
    if (last && isTerminalSendMessage(last.node)) {
        for (const tn of collectDefaultTail(last)) {
            if (!seen.has(tn.node.id)) {
                seen.add(tn.node.id)
                result.push(tn)
            }
        }
    }

    // 2. Sibling roots in the current branch — orphan chains not wired
    //    to anything, e.g. nodes that lost their incoming edge.
    for (const tn of collectSiblingRoots(branchRoots.value, activeNodes.value)) {
        if (!seen.has(tn.node.id)) {
            seen.add(tn.node.id)
            result.push(tn)
        }
    }

    return result
})

/**
 * Branches of the terminal send_message that the orphan chain can be
 * rescued under. We only surface button branches here — moving to a
 * `default` slot would just reinstate the original broken edge.
 */
interface MoveTarget {
    label: string
    handle: string
    sourceNodeId: string
}

const moveTargets = computed<MoveTarget[]>(() => {
    const last = activeNodes.value[activeNodes.value.length - 1]
    if (!last || !isTerminalSendMessage(last.node)) return []
    const config = (last.node.config ?? {}) as Record<string, unknown>
    const buttons = Array.isArray(config.buttons) ? (config.buttons as Array<Record<string, unknown>>) : []
    return buttons.map((btn, idx) => {
        const raw = btn.label
        const text = typeof raw === 'object'
            ? String(Object.values(raw as Record<string, unknown>)[0] ?? '')
            : String(raw ?? '')
        return {
            label:        text.trim() !== '' ? text.trim() : `Button ${idx + 1}`,
            handle:       String(btn.id ?? ''),
            sourceNodeId: last.node.id,
        }
    }).filter((target) => target.handle !== '')
})

function moveChainTo(target: MoveTarget) {
    const head = unreachableNodes.value[0]
    if (!head) return
    builderStore.moveChain(head.node.id, target.sourceNodeId, target.handle)
}

async function removeUnreachable() {
    if (unreachableNodes.value.length === 0) return
    const count = unreachableNodes.value.length
    const ok = await confirm({
        title:        'Delete unreachable nodes',
        message:      `Delete ${count} unreachable node${count > 1 ? 's' : ''}? `
            + 'They are not connected to any executable path and cannot be undone via canvas.',
        confirmLabel: 'Delete',
        cancelLabel:  'Keep',
        danger:       true,
    })
    if (!ok) return
    // Batch removal without per-node bridging: deleteNode() would chain
    // incoming.default to outgoing.default at every step, which in an
    // orphan tail re-creates the very `default` edge from the terminal
    // send_message that made the chain unreachable in the first place.
    builderStore.removeNodes(unreachableNodes.value.map((tn) => tn.node.id))
}

/** Breadcrumb segments based on activeBranch + node labels */
const breadcrumbs = computed(() => {
    const segments = [{ label: 'Main flow', key: 'root' }]
    const branch = selectionStore.activeBranch
    for (let i = 0; i < branch.length; i += 2) {
        const nodeId = branch[i]
        const handle = branch[i + 1]
        if (!nodeId) break
        const node = builderStore.definition.nodes.find((n) => n.id === nodeId)
        const nodeLabel = node?.label
            ?? node?.type?.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
            ?? nodeId
        segments.push({ label: nodeLabel, key: `${nodeId}` })
        if (handle) {
            let handleLabel = handle
            if (node?.type === 'send_message' && Array.isArray(node?.config?.buttons)) {
                const buttons = node.config.buttons as Array<Record<string, unknown>>
                const idx = buttons.findIndex((b) => b.id === handle)
                if (idx !== -1) {
                    const lbl = buttons[idx].label
                    const text = typeof lbl === 'object'
                        ? String(Object.values(lbl as Record<string, unknown>)[0] ?? '')
                        : String(lbl ?? '')
                    handleLabel = text.trim() !== '' ? text.trim() : `Button ${idx + 1}`
                } else {
                    handleLabel = 'Button'
                }
            }
            segments.push({ label: handleLabel, key: `${nodeId}-${handle}` })
        }
    }
    return segments
})

const isAtRoot = computed(() => selectionStore.activeBranch.length === 0)

const triggerLabel = computed(() => {
    if (!builderStore.trigger || builderStore.trigger._delete === true) {
        return {
            label: 'No trigger configured',
            sub: 'Click to add a trigger',
        }
    }

    const type = builderStore.trigger.type.replace(/_/g, ' ')
    const label = `${type.charAt(0).toUpperCase()}${type.slice(1)} trigger`

    if (builderStore.trigger.type === 'message') {
        const keywords = (builderStore.trigger.config?.keywords as unknown[]) ?? []
        const phrases = (builderStore.trigger.config?.phrases as unknown[]) ?? []
        const total = keywords.length + phrases.length

        return {
            label,
            sub: total > 0 ? `${total} message matcher${total > 1 ? 's' : ''}` : 'No phrases or keywords yet',
        }
    }

    if (builderStore.trigger.type === 'event') {
        return {
            label,
            sub: builderStore.trigger.config?.event_name ?? 'No event selected',
        }
    }

    return {
        label,
        sub: builderStore.trigger.is_active ? 'Active' : 'Inactive',
    }
})

const hoveredSlot = ref<string | null>(null)

function goToRoot() {
    selectionStore.clearBranch()
}

/**
 * True when the last visible node is terminal — no further nodes may be added.
 * Two cases for send_message:
 *   - inline keyboard with buttons: branches are the exit path
 *   - reply keyboard: pressing a reply button triggers a separate flow via
 *     keyword/trigger matching, so this flow ends here entirely
 */
const lastNodeIsTerminal = computed(() => {
    const last = activeNodes.value[activeNodes.value.length - 1]
    if (!last) return false
    const { type, config } = last.node
    // Real `end` node already terminates the chain — no decorative tail card.
    if (type === 'end') return true
    if (type !== 'send_message' || config?.content_type !== 'text_with_keyboard') return false
    const mode = config?.keyboard_mode ?? 'inline'
    if (mode === 'reply') return true
    return ((config?.buttons as unknown[] | undefined)?.length ?? 0) > 0
})

/**
 * Slot context computed per slot index. Two edge cases the renderer
 * has to special-case:
 *
 *   - **Top slot inside a branch (index 0 with active branch)** — the
 *     "previous" node doesn't exist in `activeNodes`, but we still
 *     want the new node to land under the branch parent + handle.
 *     Passing `afterNodeId = null` would route through
 *     {@link builderStore.insertNode}'s root-insertion path and lift
 *     the new node out of the branch entirely.
 *   - **Trailing slot of an empty branch** — same shape, with no
 *     activeNodes to anchor against.
 */
function slotContext(index: number): { afterNodeId: string | null; handle: string } {
    if (index > 0) {
        const previous = activeNodes.value[index - 1]
        if (previous) {
            return {afterNodeId: previous.node.id, handle: 'default'}
        }
    }
    const branch = selectionStore.activeBranch
    if (branch.length >= 2) {
        return {
            afterNodeId: branch[branch.length - 2],
            handle: branch[branch.length - 1],
        }
    }
    return {afterNodeId: null, handle: 'default'}
}

const trailingInsertContext = computed(() => {
    const last = activeNodes.value[activeNodes.value.length - 1]
    if (last) {
        return { afterNodeId: last.node.id, handle: 'default' }
    }
    const branch = selectionStore.activeBranch
    if (branch.length >= 2) {
        return {
            afterNodeId: branch[branch.length - 2],
            handle: branch[branch.length - 1],
        }
    }
    return { afterNodeId: null, handle: 'default' }
})
</script>

<template>
    <div class="panel-sequence">
        <div class="sequence-wrap">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <template v-for="(seg, i) in breadcrumbs" :key="seg.key">
                    <span v-if="i > 0" class="breadcrumb-sep">/</span>
                    <a
                        v-if="i < breadcrumbs.length - 1"
                        @click="i === 0 ? goToRoot() : null"
                    >{{ seg.label }}</a>
                    <span v-else class="breadcrumb-current">{{ seg.label }}</span>
                </template>
            </div>

            <div class="sequence-card-body">
                <!-- Trigger card (only at root) -->
                <template v-if="isAtRoot">
                    <div class="trigger-card" @click="selectionStore.selectTrigger()">
                        <div class="trigger-icon">⚡</div>
                        <div>
                            <div class="trigger-label">{{ triggerLabel.label }}</div>
                            <div v-if="triggerLabel.sub" class="trigger-sub">{{ triggerLabel.sub }}</div>
                        </div>
                    </div>
                </template>

                <!-- Nodes -->
                <template v-for="(item, index) in activeNodes" :key="item.node.id">
                    <!-- slot between nodes: hover group reveals insert button -->
                    <div
                        class="seq-slot"
                        @mouseenter="hoveredSlot = `slot-${index}`"
                        @mouseleave="hoveredSlot = null"
                    >
                        <div class="seq-connector">
                            <div class="conn-line"/>
                            <div class="conn-dot"/>
                            <div class="conn-line"/>
                        </div>
                        <FlowInsertPoint
                            :after-node-id="slotContext(index).afterNodeId"
                            :handle="slotContext(index).handle"
                            :index="index"
                            :visible="hoveredSlot === `slot-${index}`"
                        />
                        <div class="seq-connector">
                            <div class="conn-line"/>
                        </div>
                    </div>

                    <FlowConditionCard
                        v-if="item.node.type === 'condition'"
                        :index="index + 1"
                        :parent-branch="selectionStore.activeBranch"
                        :tree-node="item"
                    />
                    <FlowSendMessageCard
                        v-else-if="
                            item.node.type === 'send_message'
                                && item.node.config?.content_type === 'text_with_keyboard'
                                && (item.node.config?.keyboard_mode ?? 'inline') !== 'reply'
                                && ((item.node.config?.buttons as unknown[] | undefined)?.length ?? 0) > 0
                        "
                        :index="index + 1"
                        :parent-branch="selectionStore.activeBranch"
                        :tree-node="item"
                    />
                    <FlowNodeCard
                        v-else
                        :index="index + 1"
                        :tree-node="item"
                    />
                </template>

                <!-- trailing slot + end card — hidden when last node is inline keyboard (buttons are the exit) -->
                <template v-if="!lastNodeIsTerminal">
                    <div
                        class="seq-slot"
                        @mouseenter="hoveredSlot = 'slot-end'"
                        @mouseleave="hoveredSlot = null"
                    >
                        <div class="seq-connector">
                            <div class="conn-line"/>
                            <div class="conn-dot"/>
                            <div class="conn-line"/>
                        </div>
                        <FlowInsertPoint
                            :after-node-id="trailingInsertContext.afterNodeId"
                            :handle="trailingInsertContext.handle"
                            :index="activeNodes.length"
                            :visible="hoveredSlot === 'slot-end'"
                        />
                        <div class="seq-connector">
                            <div class="conn-line"/>
                        </div>
                    </div>
                </template>

                <!-- unreachable orphans: nodes still wired through `default` from a
                     terminal send_message. Surfaced explicitly so the author can
                     wipe them in one click rather than chase a Publish error. -->
                <div v-if="unreachableNodes.length > 0" class="unreachable-block">
                    <div class="unreachable-header">
                        <span class="unreachable-title">
                            Unreachable — {{ unreachableNodes.length }} node{{ unreachableNodes.length > 1 ? 's' : '' }}
                        </span>
                        <button class="unreachable-remove" type="button" @click="removeUnreachable">
                            Delete all
                        </button>
                    </div>
                    <div class="unreachable-hint">
                        These nodes can never run — the previous send_message exits via its buttons.
                        Move the whole chain under a button branch, or delete it before publishing.
                    </div>
                    <div v-if="moveTargets.length > 0" class="unreachable-actions">
                        <span class="unreachable-actions-label">Move chain to:</span>
                        <button
                            v-for="target in moveTargets"
                            :key="target.handle"
                            :title="`Move ${unreachableNodes.length} node(s) under ${target.label}`"
                            class="unreachable-move-btn"
                            type="button"
                            @click="moveChainTo(target)"
                        >
                            {{ target.label }} →
                        </button>
                    </div>
                    <div class="unreachable-list">
                        <FlowNodeCard
                            v-for="(item, idx) in unreachableNodes"
                            :key="item.node.id"
                            :index="activeNodes.length + idx + 1"
                            :tree-node="item"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
