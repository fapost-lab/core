<script setup lang="ts">
import {computed, ref} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {useTranslations} from '@builder/composables/useTranslations'
import {branchRuleLabel} from '@builder/utils/branchLabel'

interface FlowNodeMeta {
    id: string
    type: string
    label?: string
    config?: Record<string, unknown>
}

interface TreeNode {
    node: FlowNodeMeta
    childrenByHandle?: Record<string, TreeNode[]>
}

const props = defineProps({
    treeNode: { type: Object as () => TreeNode, required: true },
    depth: { type: Number, default: 0 },
    parentBranch: { type: Array as () => string[], default: () => [] },
})

const selectionStore = useSelectionStore()
const builderStore = useBuilderStore()
const {t} = useTranslations()
const expanded = ref(true)

const handles = computed((): string[] => Object.keys(props.treeNode.childrenByHandle ?? {}))

const isBranching = computed((): boolean => ['branch', 'condition'].includes(props.treeNode.node.type))

/**
 * Handles shown as nested branches. A condition lists every rule (wired or not) and always ends with "Otherwise",
 * whose blocks nest under it. For other types `default` is just linear continuation and is rendered flat.
 */
const branchHandles = computed((): string[] => {
    const wired = handles.value.filter((h: string) => h !== 'default')
    if (!isBranching.value) {
        return wired
    }
    const raw = props.treeNode.node.config?.rules
    const ruleHandles = Array.isArray(raw)
        ? (raw as Array<Record<string, unknown>>).map((r) => String(r.handle ?? '')).filter((h) => h !== '')
        : []
    const extra = wired.filter((h: string) => !ruleHandles.includes(h))
    return [...ruleHandles, ...extra, 'default']
})
const defaultChildren = computed((): TreeNode[] => isBranching.value ? [] : (props.treeNode.childrenByHandle?.default ?? []))
const colors = computed(() => nodeColors(props.treeNode.node.type))

const nodeLabel = computed((): string => {
    const type = props.treeNode.node.type
    // Comments all share one type name — the note's first line is what tells
    // them apart in the outline.
    if (type === 'comment') {
        const text = (props.treeNode.node.config ?? {}).text
        const firstLine = typeof text === 'string' ? text.split('\n')[0].trim() : ''
        if (firstLine !== '') return firstLine
    }
    return props.treeNode.node.label
        ?? type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
})

/**
 * The structure panel navigates, it does not select: opening the config drawer
 * on every tree click covers the canvas and gets in the way of moving around.
 * Properties open when the author clicks the node's card.
 */
function scrollToCard(nodeId: string) {
    requestAnimationFrame(() => {
        document.getElementById(`node-card-${nodeId}`)?.scrollIntoView({behavior: 'smooth', block: 'center'})
    })
}

/**
 * `parentBranch` accumulates `(nodeId, 'default')` pairs while the tree walks linear-continuation children — noise for
 * the breadcrumb and the canvas branch context, which only care about meaningful branching points (button.id, rule
 * handles). The exception is a condition's `default`: that is its "Otherwise" branch, a real choice, so it is kept.
 * `resolveBranchRoots` uses a recursive lookup, so skipping the other default pairs doesn't break deep navigation.
 */
function meaningfulPath(): string[] {
    const meaningful: string[] = []
    for (let i = 0; i < props.parentBranch.length; i += 2) {
        const nodeId = props.parentBranch[i]
        const segmentHandle = props.parentBranch[i + 1]
        if (!nodeId || !segmentHandle) {
            continue
        }
        if (segmentHandle === 'default' && !isBranchingNodeId(nodeId)) {
            continue
        }
        meaningful.push(nodeId, segmentHandle)
    }
    return meaningful
}

function isBranchingNodeId(nodeId: string): boolean {
    const type = builderStore.definition.nodes.find((n) => n.id === nodeId)?.type
    return type === 'branch' || type === 'condition'
}

function selectNode() {
    scrollToCard(props.treeNode.node.id)
    const meaningfulBranch = meaningfulPath()

    if (meaningfulBranch.length > 0) {
        selectionStore.setActiveBranch(meaningfulBranch)
        return
    }

    selectionStore.clearBranch()
}

function focusBranch(handle: string) {
    selectionStore.setActiveBranch([...meaningfulPath(), props.treeNode.node.id, handle])
}

function resolveHandleLabel(handle: string): string {
    if (props.treeNode.node.type === 'send_message') {
        const buttons = (props.treeNode.node.config?.buttons as Array<Record<string, unknown>>) ?? []
        const idx = buttons.findIndex((b) => b.id === handle)
        if (idx !== -1) {
            const lbl = buttons[idx].label
            const text = lbl !== null && typeof lbl === 'object'
                ? String(Object.values(lbl as Record<string, unknown>)[0] ?? '')
                : String(lbl ?? '')
            return text.trim() !== '' ? text.trim() : `Button ${idx + 1}`
        }
        return `Button`
    }
    if (props.treeNode.node.type === 'loop' && handle === 'loop') {
        return 'Loop body'
    }
    // Branch/condition rule handles are generated ids — show the rule's label.
    if (props.treeNode.node.type === 'branch' || props.treeNode.node.type === 'condition') {
        if (handle === 'default') return t('nodes.branch.otherwise')
        const rules = Array.isArray(props.treeNode.node.config?.rules)
            ? (props.treeNode.node.config!.rules as Array<Record<string, unknown>>)
            : []
        const index = rules.findIndex((r) => r.handle === handle)
        return index === -1 ? t('nodes.branch.branch_n').replace(':n', '?') : branchRuleLabel(rules[index], index, t)
    }
    return handle
}
</script>

<template>
    <div>
        <div
            class="tree-item"
            :class="{ active: selectionStore.selectedNodeId === treeNode.node.id }"
            @click="selectNode"
        >
            <div
                class="node-icon"
                :style="{ background: colors.bg, color: colors.color }"
            ><NodeIcon :type="treeNode.node.type" /></div>
            <span class="truncate" style="min-width:0;flex:1">{{ nodeLabel }}</span>
            <button
                v-if="branchHandles.length > 0"
                type="button"
                style="font-size:10px;color:var(--text-3);flex-shrink:0"
                @click.stop="expanded = !expanded"
            >
                {{ expanded ? '▾' : '▸' }}
            </button>
        </div>

        <!-- Branching handles (yes/no, switch cases) — shown nested with label -->
        <div v-if="expanded && branchHandles.length > 0" class="tree-children">
            <template v-for="handle in branchHandles" :key="`${treeNode.node.id}-${handle}`">
                <div
                    class="tree-branch"
                    :class="{ active: selectionStore.activeBranch.includes(treeNode.node.id) && selectionStore.activeBranch.includes(handle) }"
                    @click="focusBranch(handle)"
                >
                    <div
                        class="branch-dot"
                        :class="handle === 'yes' ? 'yes' : handle === 'no' ? 'no' : ''"
                    />
                    {{ resolveHandleLabel(handle) }}
                </div>
                <div class="tree-children">
                    <FlowStructureNode
                        v-for="child in (treeNode.childrenByHandle ?? {})[handle] ?? []"
                        :key="child.node.id"
                        :tree-node="child"
                        :depth="depth + 1"
                        :parent-branch="[...parentBranch, treeNode.node.id, handle]"
                    />
                </div>
            </template>
        </div>

        <!-- Default handle — render children flat at same level (linear continuation).
             For a loop, `default` is the main-flow continuation AFTER the loop
             (not a sub-branch), so it must stay visible even when the loop's
             body branch is collapsed — collapsing only hides the `loop body`.
             Pure branchers (condition) keep their `default`/Otherwise gated. -->
        <template v-if="expanded || treeNode.node.type === 'loop'">
            <FlowStructureNode
                v-for="child in defaultChildren"
                :key="child.node.id"
                :tree-node="child"
                :depth="depth"
                :parent-branch="[...parentBranch, treeNode.node.id, 'default']"
            />
        </template>
    </div>
</template>
