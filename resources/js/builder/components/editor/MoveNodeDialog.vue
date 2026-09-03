<script lang="ts" setup>
import {computed, ref, watch} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'
import {descendantIds, type FlowNodeLike, nodeHandles} from '@builder/utils/nodeHandles'
import BaseModal from '@builder/components/editor/config/overrides/BaseModal.vue'

/**
 * Move a node anywhere in the graph by reparenting onto a chosen
 * (parent, handle) slot. Backend `builderStore.moveNode` / `moveChain`
 * handle the edge-rewriting; this dialog enumerates valid destinations
 * with enough context (path breadcrumb, content snippet) that the
 * author can tell apart nodes that share a type label.
 */
const props = defineProps({
    open: {type: Boolean, required: true},
    nodeId: {type: String as () => string | null, default: null},
    /**
     * Preset for the "move with descendants" toggle — the author can still
     * change it. Set by callers who already know a whole chain is travelling,
     * e.g. rescuing a branch out of a node being deleted.
     */
    chain: {type: Boolean, default: false},
})

const emit = defineEmits(['close'])

const builderStore = useBuilderStore()
const registryStore = useRegistryStore()

interface PathStep {
    label: string
    handle: string
}

interface TreeWalkNode {
    node: FlowNodeLike
    childrenByHandle?: Record<string, TreeWalkNode[]>
}

interface Destination {
    parentId: string | null
    parentLabel: string
    parentSnippet: string | null
    parentPath: PathStep[]
    handle: string
    handleLabel: string
    occupied: boolean
    isRoot: boolean
}

const search = ref('')
const withDescendants = ref(false)

watch(() => props.open, (open) => {
    if (open) {
        search.value = ''
        withDescendants.value = props.chain
    }
})

const movingNode = computed<FlowNodeLike | null>(() => {
    if (!props.nodeId) return null
    const node = builderStore.definition.nodes.find((n) => n.id === props.nodeId)
    return node ? (node as unknown as FlowNodeLike) : null
})

const movingLabel = computed<string>(() => {
    const node = movingNode.value
    if (!node) return ''
    return labelForNode(node)
})

/**
 * Walk the tree once, recording the breadcrumb path that leads to
 * each node. Builder graphs have at most one incoming edge per node,
 * so each id maps to a single path — index by id for O(1) lookup
 * while rendering destinations.
 */
/**
 * Map of nodeId → branching breadcrumb leading to that node. Mirrors
 * the canvas breadcrumb semantics: only meaningful branch points are
 * recorded (button branches, condition rules) — `default`-handle
 * descents are linear continuations within the same branch and add
 * no navigational value.
 */
const pathsByNodeId = computed<Map<string, PathStep[]>>(() => {
    const map = new Map<string, PathStep[]>()

    const visit = (treeNode: TreeWalkNode, path: PathStep[]) => {
        map.set(treeNode.node.id, path)
        for (const [handle, children] of Object.entries(treeNode.childrenByHandle ?? {})) {
            const isBranch = handle !== 'default'
            const nextPath: PathStep[] = isBranch
                ? [
                    ...path,
                    {
                        label: labelForNode(treeNode.node),
                        handle: resolveHandleLabel(treeNode.node, handle),
                    },
                ]
                : path
            for (const child of children) {
                visit(child, nextPath)
            }
        }
    }

    const roots = (builderStore.tree ?? []) as TreeWalkNode[]
    for (const root of roots) {
        visit(root, [])
    }
    return map
})

/**
 * Serialise a branch path as a string so we can equality-compare two
 * paths cheaply. The dialog uses this to hide destinations that would
 * land the moving node back into its own branch — there's no value in
 * surfacing "same place, different parent" picks.
 */
function pathKey(path: PathStep[]): string {
    return path.map((step) => `${step.label}::${step.handle}`).join('>')
}

const destinations = computed<Destination[]>(() => {
    const node = movingNode.value
    if (!node) return []

    const edges = builderStore.definition.edges
    const blocked = descendantIds(node.id, edges, withDescendants.value)
    const currentBranchKey = pathKey(pathsByNodeId.value.get(node.id) ?? [])

    const occupiedSlots = new Set<string>()
    for (const edge of edges) {
        occupiedSlots.add(`${edge.from}::${edge.handle ?? 'default'}`)
    }

    const result: Destination[] = []

    // Synthetic "Main flow root" destination — only offer it if the
    // moving node isn't already the entry. Picking this promotes the
    // moving node to root, current entry slots in as its successor.
    const incomingByTarget = new Set(edges.map((edge) => edge.to))
    const isAlreadyRoot = !incomingByTarget.has(node.id)
    if (!isAlreadyRoot) {
        result.push({
            parentId: null,
            parentLabel: 'Main flow',
            parentSnippet: null,
            parentPath: [],
            handle: 'root',
            handleLabel: 'Entry',
            occupied: builderStore.definition.nodes.some(
                (n) => n.id !== node.id && !incomingByTarget.has(n.id),
            ),
            isRoot: true,
        })
    }

    builderStore.definition.nodes.forEach((candidate) => {
        if (blocked.has(candidate.id)) return

        const candidatePath = pathsByNodeId.value.get(candidate.id) ?? []
        const candidatePathKey = pathKey(candidatePath)
        const handles = nodeHandles(candidate as unknown as FlowNodeLike)
        for (const slot of handles) {
            // A destination lands the moving node into a branch
            // identified by (candidatePath, slot.handle if non-default)
            // — for default slots the branch is simply candidatePath
            // since `default` is a linear continuation, not a new
            // branching point. Hide picks whose resulting branch is
            // the moving node's current branch (no-op moves).
            const resultingBranchKey = slot.handle === 'default'
                ? candidatePathKey
                : pathKey([
                    ...candidatePath,
                    {
                        label: labelForNode(candidate as unknown as FlowNodeLike),
                        handle: resolveHandleLabel(candidate as unknown as FlowNodeLike, slot.handle),
                    },
                ])
            if (resultingBranchKey === currentBranchKey) {
                continue
            }
            result.push({
                parentId: candidate.id,
                parentLabel: labelForNode(candidate as unknown as FlowNodeLike),
                parentSnippet: contentSnippet(candidate as unknown as FlowNodeLike),
                parentPath: candidatePath,
                handle: slot.handle,
                handleLabel: slot.label,
                occupied: occupiedSlots.has(`${candidate.id}::${slot.handle}`),
                isRoot: false,
            })
        }
    })
    return result
})

const filtered = computed<Destination[]>(() => {
    const query = search.value.trim().toLowerCase()
    if (query === '') return destinations.value
    return destinations.value.filter((dest) =>
            dest.parentLabel.toLowerCase().includes(query)
            || dest.handleLabel.toLowerCase().includes(query)
            || (dest.parentSnippet ?? '').toLowerCase().includes(query)
            || (dest.parentId ?? '').toLowerCase().includes(query)
            || dest.parentPath.some((step) =>
                step.label.toLowerCase().includes(query)
                || step.handle.toLowerCase().includes(query),
            ),
    )
})

function labelForNode(node: FlowNodeLike): string {
    const meta = registryStore.getByType(node.type, node.version ?? 1)
    if (meta?.label) return meta.label as string
    return node.type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function resolveHandleLabel(node: FlowNodeLike, handle: string): string {
    if (node.type === 'send_message') {
        const buttons = (node.config?.buttons as Array<Record<string, unknown>>) ?? []
        const idx = buttons.findIndex((b) => b.id === handle)
        if (idx === -1) return handle
        const raw = buttons[idx].label
        const text = typeof raw === 'object'
            ? String(Object.values(raw as Record<string, unknown>)[0] ?? '')
            : String(raw ?? '')
        return text.trim() !== '' ? text.trim() : `Button ${idx + 1}`
    }
    return handle
}

/**
 * Short content preview used to tell apart same-typed nodes — text for
 * send_message, expression for condition, configured variable for
 * input/assign. Trimmed to a single line so the destination row stays
 * compact.
 */
function contentSnippet(node: FlowNodeLike): string | null {
    const config = (node.config ?? {}) as Record<string, unknown>
    if (node.type === 'send_message') {
        const contentType = typeof config.content_type === 'string' ? config.content_type : 'text'
        const isMedia = ['image', 'document', 'video', 'voice'].includes(contentType)

        const mediaFile = config.media_file as Record<string, unknown> | null | undefined
        const fileName = mediaFile && typeof mediaFile.name === 'string' ? mediaFile.name : null

        const text = config.text ?? config.caption
        const textValue = text && typeof text === 'object'
            ? String(Object.values(text as Record<string, unknown>)[0] ?? '')
            : typeof text === 'string' ? text : ''

        // Media-type nodes lead with the file name — that's what the
        // author sees on the canvas card and the most reliable
        // identifier across same-shaped sends. Text/caption rides
        // along after, if present.
        if (isMedia && fileName !== null) {
            return textValue !== ''
                ? truncate(`${fileName} · ${textValue}`)
                : truncate(fileName)
        }

        if (textValue !== '') {
            return truncate(textValue)
        }

        if (fileName !== null) {
            return truncate(fileName)
        }

        if (contentType !== 'text') {
            return contentType.replace(/_/g, ' ')
        }
        return null
    }
    if (node.type === 'condition' || node.type === 'branch') {
        if (typeof config.expression === 'string') return truncate(config.expression)
        const rules = Array.isArray(config.rules) ? (config.rules as Array<Record<string, unknown>>) : []
        return rules.length > 0 ? `${rules.length} rule${rules.length > 1 ? 's' : ''}` : null
    }
    if (node.type === 'input') {
        const variable = config.variable as Record<string, unknown> | undefined
        if (variable && typeof variable.name === 'string') return `→ ${variable.name}`
        return null
    }
    if (node.type === 'assign') {
        const ops = Array.isArray(config.operations) ? config.operations : []
        return ops.length > 0 ? `${ops.length} op${ops.length > 1 ? 's' : ''}` : null
    }
    if (node.type === 'delay') {
        return typeof config.seconds === 'number' ? `${config.seconds}s` : null
    }
    if (node.type === 'call') {
        return typeof config.url === 'string' ? truncate(config.url) : null
    }
    return null
}

function truncate(text: string, max = 40): string {
    const clean = text.trim().replace(/\s+/g, ' ')
    return clean.length > max ? `${clean.slice(0, max)}…` : clean
}

function pick(dest: Destination) {
    if (!props.nodeId) return
    // Both store ops accept `null` parentId to mean "promote to root".
    if (withDescendants.value) {
        builderStore.moveChain(props.nodeId, dest.parentId, dest.handle)
    } else {
        builderStore.moveNode(props.nodeId, dest.parentId, dest.handle)
    }
    emit('close')
}
</script>

<template>
    <BaseModal
        :open="open"
        :title="movingLabel ? `Move ${movingLabel} to…` : 'Move node to…'"
        width="560px"
        @close="emit('close')"
    >
        <div class="move-dialog">
            <input
                v-model="search"
                class="field-input move-search"
                placeholder="Search by node, branch, content, or id…"
                type="text"
            >
            <label class="move-chain-toggle">
                <input
                    :checked="withDescendants"
                    class="toggle-check"
                    type="checkbox"
                    @change="withDescendants = ($event.target as HTMLInputElement).checked"
                >
                <span>
                    Move with descendants
                    <span class="move-chain-hint">— take the linear tail along instead of bridging the gap</span>
                </span>
            </label>
            <p v-if="destinations.length === 0" class="move-empty">
                No valid destinations — every other node is downstream of this one.
            </p>
            <p v-else-if="filtered.length === 0" class="move-empty">
                No destinations match "{{ search }}".
            </p>
            <ul v-else class="move-list">
                <li
                    v-for="dest in filtered"
                    :key="`${dest.parentId ?? 'root'}::${dest.handle}`"
                    :class="{ 'move-row--root': dest.isRoot }"
                    class="move-row"
                    @click="pick(dest)"
                >
                    <div class="move-row-main">
                        <span class="move-row-target">
                            <span class="move-row-parent">{{ dest.parentLabel }}</span>
                            <span v-if="dest.parentSnippet" class="move-row-snippet">· {{ dest.parentSnippet }}</span>
                            <span class="move-row-arrow">→</span>
                            <span class="move-row-handle">{{ dest.handleLabel }}</span>
                        </span>
                        <span
                            :class="dest.occupied ? 'move-row-action--splice' : 'move-row-action--insert'"
                            :title="dest.occupied
                                ? 'Slot is occupied. The node will be spliced in — the current child becomes its default successor.'
                                : 'Slot is empty. The node will land as the first child of this branch.'"
                            class="move-row-action"
                        >{{ dest.occupied ? 'Splice' : 'Insert' }}</span>
                    </div>
                    <div v-if="dest.parentPath.length > 0" class="move-row-path">
                        <span class="move-row-path-root">Main</span>
                        <template v-for="(step, idx) in dest.parentPath" :key="idx">
                            <span class="move-row-path-sep">/</span>
                            <span class="move-row-path-step">
                                {{ step.label }}
                                <span v-if="step.handle !== '·'" class="move-row-path-handle">: {{ step.handle }}</span>
                            </span>
                        </template>
                    </div>
                    <div v-else class="move-row-path move-row-path--root">
                        <span class="move-row-path-root">Main flow root</span>
                    </div>
                </li>
            </ul>
        </div>
    </BaseModal>
</template>

<style scoped>
.move-dialog {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 200px;
}

.move-search {
    width: 100%;
}

.move-chain-toggle {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
    font-size: 12px;
    color: var(--text-2);
}

.move-chain-toggle input {
    margin-top: 2px;
    flex-shrink: 0;
}

.move-chain-hint {
    color: var(--text-3);
    font-size: 11px;
    display: block;
}

.move-empty {
    margin: 16px 0;
    text-align: center;
    color: var(--text-3);
    font-size: 12.5px;
}

.move-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 400px;
    overflow-y: auto;
}

.move-row {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    cursor: pointer;
    transition: background .12s, border-color .12s;
}

.move-row:hover {
    background: var(--primary-bg);
    border-color: var(--primary);
}

.move-row--root {
    border-style: dashed;
    border-color: var(--primary);
    background: var(--surface-2);
}

.move-row--root .move-row-parent {
    color: var(--primary);
}

.move-row-main {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
}

.move-row-target {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    min-width: 0;
}

.move-row-parent {
    font-weight: 600;
    color: var(--text);
    flex-shrink: 0;
}

.move-row-id {
    font-family: 'Victor Mono', monospace;
    font-size: 10.5px;
    color: var(--text-3);
    background: var(--surface-2);
    padding: 1px 5px;
    border-radius: 4px;
    flex-shrink: 0;
}

.move-row-snippet {
    color: var(--text-3);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
    font-style: italic;
}

.move-row-arrow {
    color: var(--text-3);
    flex-shrink: 0;
}

.move-row-handle {
    color: var(--primary);
    font-weight: 500;
    flex-shrink: 0;
}

.move-row-action {
    font-size: 10.5px;
    padding: 1px 7px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 600;
    flex-shrink: 0;
}

.move-row-action--insert {
    color: var(--sage);
    background: var(--sage-bg);
}

.move-row-action--splice {
    color: var(--amber);
    background: var(--amber-bg);
}

.move-row-path {
    font-size: 10.5px;
    color: var(--text-3);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px;
}

.move-row-path-root {
    font-weight: 500;
}

.move-row-path-sep {
    color: var(--border-2, var(--border));
}

.move-row-path-step {
    color: var(--text-2);
}

.move-row-path-handle {
    color: var(--primary);
    font-weight: 500;
}

.move-row-path--root {
    color: var(--text-3);
    font-style: italic;
}
</style>
