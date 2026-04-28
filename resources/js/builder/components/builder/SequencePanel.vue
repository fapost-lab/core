<script setup>
import { computed, provide } from 'vue'
import { useBuilderStore }    from '@builder/store/builderStore'
import { useSelectionStore }  from '@builder/store/selectionStore'
import { useNavigationStore } from '@builder/store/navigationStore'
import Breadcrumb   from './Breadcrumb.vue'
import TriggerCard  from './TriggerCard.vue'
import SeqConnector from './SeqConnector.vue'
import InsertPoint  from './InsertPoint.vue'
import NodeCard     from './NodeCard.vue'
import EndCard      from './EndCard.vue'

const builder  = useBuilderStore()
const selection = useSelectionStore()
const nav      = useNavigationStore()

/**
 * Build ordered chain of nodes starting from a given nodeId,
 * following outputs.default.next until no next exists.
 * Stops before condition children (they live in branches, not the main chain).
 *
 * @param {string|null} startId
 * @param {Record<string, object>} nodeMap
 * @returns {object[]}
 */
function buildChain(startId, nodeMap) {
    const chain = []
    const visited = new Set()
    let id = startId

    while (id && !visited.has(id) && nodeMap[id]) {
        visited.add(id)
        chain.push(nodeMap[id])
        id = nodeMap[id].outputs?.default?.next ?? null
    }

    return chain
}

const nodeMap = computed(() => {
    const map = {}
    for (const n of builder.definition.nodes) {
        map[n.id] = n
    }
    return map
})

/** Root-level trigger (first node not reachable from any other node's default output). */
const trigger = computed(() => {
    const reachable = new Set(
        builder.definition.nodes
            .flatMap((n) => Object.values(n.outputs ?? {}).map((o) => o?.next))
            .filter(Boolean),
    )
    // Prefer a node of type 'trigger'; fallback: node not reachable from others
    return (
        builder.definition.nodes.find((n) => n.type === 'trigger') ??
        builder.definition.nodes.find((n) => !reachable.has(n.id)) ??
        null
    )
})

/** The first non-trigger node in the root chain. */
const rootStartId = computed(() => {
    if (!trigger.value) {
        return builder.definition.nodes[0]?.id ?? null
    }
    return trigger.value.outputs?.default?.next ?? null
})

/**
 * Nodes to display in the sequence column.
 * - Root level (path empty): chain from rootStartId
 * - Branch level: chain from the branch output of the last path entry
 */
const displayedNodes = computed(() => {
    const path = nav.path

    if (path.length === 0) {
        return buildChain(rootStartId.value, nodeMap.value)
    }

    const last = path[path.length - 1]
    const parentNode = nodeMap.value[last.nodeId]
    const branchStartId = parentNode?.outputs?.[last.branchKey]?.next ?? null

    return buildChain(branchStartId, nodeMap.value)
})

function scrollToNode(nodeId) {
    const el = document.getElementById(`node-${nodeId}`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

provide('scrollToNode', scrollToNode)

function onSelectNode(nodeId) {
    selection.select(nodeId)
}

function onNavigateBranch(nodeId, branchKey) {
    nav.navigateToBranch(nodeId, branchKey)
}

/**
 * Returns true when the last node in the current sequence is a branching terminal —
 * i.e., a send_message with inline keyboard or a condition node.
 * In that case "+ Add block" at the tail makes no logical sense.
 */
const lastNodeIsBranchingTerminal = computed(() => {
    const last = displayedNodes.value.at(-1)
    if (!last) return false
    if (last.type === 'condition' || last.type === 'switch') return true
    if (last.type === 'send_message') {
        const cfg = last.config ?? {}
        return cfg.content_type === 'text_with_keyboard' && cfg.keyboard_mode === 'inline'
    }
    return false
})

/** Branch buttons of the last branching terminal node, for direct navigation. */
const terminalBranchButtons = computed(() => {
    const last = displayedNodes.value.at(-1)
    if (!last || !lastNodeIsBranchingTerminal.value) return []
    if (last.type === 'send_message') {
        return (last.config?.buttons ?? []).map((btn) => ({
            key: btn.id,
            label: typeof btn.label === 'object' ? Object.values(btn.label)[0] ?? 'Button' : (btn.label || 'Button'),
        }))
    }
    return []
})
</script>

<template>
    <div class="sequence-panel" @click.self="selection.clear()">
        <div class="sequence-wrap">
            <Breadcrumb />

            <!-- Trigger (root only) -->
            <template v-if="nav.path.length === 0">
                <TriggerCard
                    :trigger="trigger"
                    @select="selection.select('__trigger__')"
                />
            </template>

            <!-- Nodes -->
            <template v-for="(node, i) in displayedNodes" :key="node.id">
                <SeqConnector />
                <InsertPoint :after-node-id="i === 0 ? null : displayedNodes[i - 1]?.id" />
                <SeqConnector />

                <NodeCard
                    :node="node"
                    :seq-num="i + 1"
                    :is-selected="selection.selectedNodeId === node.id"
                    @select="onSelectNode"
                    @navigate-branch="onNavigateBranch"
                />
            </template>

            <!-- End: normal sequence tail -->
            <template v-if="!lastNodeIsBranchingTerminal">
                <SeqConnector />
                <InsertPoint :after-node-id="displayedNodes.at(-1)?.id ?? null" />
                <SeqConnector />
                <EndCard />
            </template>

            <!-- End: branching terminal — show branch entry buttons instead of + Add block -->
            <template v-else>
                <SeqConnector />
                <div class="branch-entry-list">
                    <button
                        v-for="btn in terminalBranchButtons"
                        :key="btn.key"
                        class="branch-entry-btn"
                        @click="onNavigateBranch(displayedNodes.at(-1).id, btn.key)"
                    >
                        ▶ {{ btn.label }}
                    </button>
                    <!-- condition / switch have no extra buttons here; EndCard still makes sense -->
                    <template v-if="terminalBranchButtons.length === 0">
                        <SeqConnector />
                        <EndCard />
                    </template>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.sequence-panel {
    flex: 1;
    overflow-y: auto;
    background: var(--bg);
    min-width: 0;
}
.sequence-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 16px 60px;
    gap: 0;
    min-height: 100%;
}
.branch-entry-list {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    width: 100%;
    max-width: 320px;
    padding: 8px 0 16px;
}
.branch-entry-btn {
    width: 100%;
    padding: 10px 16px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}
.branch-entry-btn:hover {
    border-color: var(--accent);
    background: var(--accent-light, color-mix(in srgb, var(--accent) 10%, transparent));
}
</style>
