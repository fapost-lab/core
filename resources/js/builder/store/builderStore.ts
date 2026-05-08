import {defineStore} from 'pinia'
import {computed, ref, watch} from 'vue'
import {nanoid} from 'nanoid'
import {buildTree} from '@builder/utils/buildTree'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useRegistryStore} from '@builder/store/registryStore'
import type {
  ActiveTab,
  BuilderFlowPayload,
  BuilderTriggerPayload,
  FlowDefinition,
  FlowEdge,
  FlowNode,
  NodeConfig,
  SaveStatus,
  TriggerType,
  ValidationResult,
} from '@builder/dto/types'

/** Internal full trigger shape (no delete marker). */
interface ActiveTrigger {
    _delete?: false
    type: TriggerType
    is_active: boolean
    priority: number
    config: Record<string, unknown>
}

function normalizeDefinition(raw: unknown): FlowDefinition {
    if (raw == null) {
        return { nodes: [], edges: [] }
    }

    if (Array.isArray(raw)) {
        return { nodes: raw as FlowNode[], edges: [] }
    }

    if (typeof raw === 'object') {
        const obj = raw as { nodes?: unknown; edges?: unknown }
        return {
            nodes: Array.isArray(obj.nodes) ? (obj.nodes as FlowNode[]) : [],
            edges: Array.isArray(obj.edges) ? (obj.edges as FlowEdge[]) : [],
        }
    }

    return { nodes: [], edges: [] }
}

function normalizeTrigger(raw: unknown): BuilderTriggerPayload | null {
    if (!raw || typeof raw !== 'object') {
        return null
    }

    const trigger = raw as { _delete?: unknown; type?: unknown; is_active?: unknown; priority?: unknown; config?: unknown }

    if (trigger._delete === true) {
        return { _delete: true }
    }

    if (typeof trigger.type !== 'string') {
        return null
    }

    return {
        type: trigger.type as TriggerType,
        is_active: typeof trigger.is_active === 'boolean' ? trigger.is_active : true,
        priority: Number.isInteger(trigger.priority) ? (trigger.priority as number) : 100,
        config: typeof trigger.config === 'object' && trigger.config !== null
            ? (trigger.config as Record<string, unknown>)
            : {},
    }
}

function defaultTrigger(): ActiveTrigger {
    return {
        type: 'message',
        is_active: true,
        priority: 100,
        config: { keywords: [], phrases: [] },
    }
}

export const useBuilderStore = defineStore('builder', () => {
    const flowId = ref<string | null>(null)
    const flowName = ref('')
    const draftVersion = ref<number | null>(null)
    const publishedVersion = ref<number | null>(null)
    const definition = ref<FlowDefinition>({ nodes: [], edges: [] })
    const trigger = ref<BuilderTriggerPayload | null>(null)
    const availableEvents = ref<string[]>([])
    const contentBaseLanguage = ref('en')
    const availableLanguages = ref<string[]>([])
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const tree = computed(() => buildTree(definition.value.nodes ?? [], definition.value.edges ?? []) as any)
    const saveStatus = ref<SaveStatus>('idle')
    // True from the moment the user mutates definition or trigger after the
    // last successful save. Flips back to false on `setSaveStatus('saved')`
    // and on `init()` (fresh hydrate). Watch is enabled only post-hydration
    // so the initial assignment in init() doesn't mark the flow dirty.
    const isDirty   = ref(false)
    let hydrated    = false
    const activeTab = ref<ActiveTab>('builder')
    const validationResult = ref<ValidationResult | null>(null)
    const validationOpen = ref(false)
    const undoStack = ref<string[]>([])
    const redoStack = ref<string[]>([])
    const MAX_UNDO = 50

    const nodesWithErrors = computed<Set<string>>(() => {
        const errors = validationResult.value?.errors
        if (!Array.isArray(errors) || errors.length === 0) {
            return new Set()
        }

        return new Set(
            errors
                .map((error) => error?.path?.split('.')?.[1])
                .filter(Boolean) as string[],
        )
    })

    function init(flow: BuilderFlowPayload) {
        flowId.value = flow.flowId
        flowName.value = flow.name
        draftVersion.value = flow.draftVersion
        publishedVersion.value = flow.publishedVersion ?? null
        definition.value = normalizeDefinition(flow.definition)
        trigger.value = normalizeTrigger(flow.trigger)
        availableEvents.value = Array.isArray(flow.availableEvents) ? flow.availableEvents : []
        contentBaseLanguage.value = flow.contentBaseLanguage ?? 'en'
        availableLanguages.value = Array.isArray(flow.availableLanguages) ? flow.availableLanguages : []
        isDirty.value = false
        hydrated      = true
    }

    function setDraftVersion(v: number | null) {
        draftVersion.value = v
    }

    function setPublishedVersion(version: number | null) {
        publishedVersion.value = version
    }

    function setTrigger(nextTrigger: BuilderTriggerPayload | null) {
        trigger.value = normalizeTrigger(nextTrigger)
    }

    function updateTrigger(patch: Record<string, unknown>) {
        const current = (trigger.value as ActiveTrigger | null) ?? defaultTrigger()
        trigger.value = {
            ...current,
            ...patch,
            config: {
                ...(current.config ?? defaultTrigger().config),
                ...((patch.config as Record<string, unknown>) ?? {}),
            },
        } as BuilderTriggerPayload
    }

    function deleteTrigger() {
        trigger.value = { _delete: true }
    }

    function setSaveStatus(status: SaveStatus) {
        saveStatus.value = status
        if (status === 'saved') {
            isDirty.value = false
        }
    }

    function setActiveTab(tab: ActiveTab) {
        activeTab.value = tab
    }

    function setValidationResult(result: ValidationResult | null, open = true) {
        validationResult.value = result
        if (open) {
            validationOpen.value = true
        }
    }

    function closeValidation() {
        validationOpen.value = false
    }

    function snapshot() {
        undoStack.value.push(JSON.stringify(definition.value))
        if (undoStack.value.length > MAX_UNDO) {
            undoStack.value.shift()
        }
        redoStack.value = []
    }

    function undo() {
        if (!undoStack.value.length) {
            return
        }

        redoStack.value.push(JSON.stringify(definition.value))
        definition.value = JSON.parse(undoStack.value.pop()!) as FlowDefinition
    }

    function redo() {
        if (!redoStack.value.length) {
            return
        }

        undoStack.value.push(JSON.stringify(definition.value))
        definition.value = JSON.parse(redoStack.value.pop()!) as FlowDefinition
    }

    function insertNode(afterNodeId: string, handle = 'default', type: string, version: number): string | null {
        const newNodeId = nanoid(10)

        if (!afterNodeId) {
            const incomingNodeIds = new Set(definition.value.edges.map((edge) => edge.to))
            const roots = definition.value.nodes.filter((node) => !incomingNodeIds.has(node.id))

            // Allow inserting into an empty flow (0 roots) or before the single root (1 root).
            if (roots.length > 1) {
                return null
            }
        }

        snapshot()

        const edgeIndex = definition.value.edges.findIndex(
            (edge) => edge.from === afterNodeId && (edge.handle ?? 'default') === handle,
        )

        const registryStore = useRegistryStore()
        const defaultConfig: NodeConfig = registryStore.getByType(type, version)?.config_schema?.default_config ?? {}

        const newNode: FlowNode = {
            id: newNodeId,
            type,
            version,
            config: { ...defaultConfig },
        }

        definition.value.nodes.push(newNode)

        if (!afterNodeId) {
            const incomingNodeIds = new Set(definition.value.edges.map((edge) => edge.to))
            const roots = definition.value.nodes.filter((node) => node.id !== newNodeId && !incomingNodeIds.has(node.id))

            if (roots.length > 0) {
                definition.value.edges.push({ id: nanoid(10), from: newNodeId, to: roots[0].id, handle: 'default' })
            }
        } else if (edgeIndex !== -1) {
            const existingEdge = definition.value.edges[edgeIndex]
            const previousTargetId = existingEdge.to

            definition.value.edges[edgeIndex] = { id: nanoid(10), from: afterNodeId, to: newNodeId, handle }
            definition.value.edges.push({ id: nanoid(10), from: newNodeId, to: previousTargetId, handle: 'default' })
        } else {
            definition.value.edges.push({ id: nanoid(10), from: afterNodeId, to: newNodeId, handle })
        }

        return newNodeId
    }

    function deleteNode(nodeId: string): boolean {
        const selectionStore = useSelectionStore()

        if (!nodeId) {
            return false
        }

        const incomingEdges = definition.value.edges.filter((edge) => edge.to === nodeId)
        const outgoingDefaultEdges = definition.value.edges.filter(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') === 'default',
        )
        const hasNonDefaultOutgoingEdges = definition.value.edges.some(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') !== 'default',
        )

        if (hasNonDefaultOutgoingEdges || incomingEdges.length > 1 || outgoingDefaultEdges.length > 1) {
            return false
        }

        snapshot()

        const incomingEdge = incomingEdges[0] ?? null
        const outgoingEdge = outgoingDefaultEdges[0] ?? null

        definition.value.nodes = definition.value.nodes.filter((node) => node.id !== nodeId)
        definition.value.edges = definition.value.edges.filter((edge) => edge.from !== nodeId && edge.to !== nodeId)

        if (incomingEdge && outgoingEdge) {
            definition.value.edges.push({
                id: nanoid(10),
                from: incomingEdge.from,
                to: outgoingEdge.to,
                handle: incomingEdge.handle ?? 'default',
            })
        }

        if (selectionStore.selectedNodeId === nodeId) {
            selectionStore.clear()
        }

        return true
    }

    function moveNodeUp(nodeId: string) {
        const incomingEdge = definition.value.edges.find((edge) => edge.to === nodeId)
        if (!incomingEdge) {
            return
        }

        const predecessorId = incomingEdge.from
        const predecessorIncoming = definition.value.edges.find((edge) => edge.to === predecessorId)
        if (!predecessorIncoming) {
            return
        }

        snapshot()
        swapAdjacentNodes(predecessorId, nodeId)
    }

    function moveNodeDown(nodeId: string) {
        const outgoingEdge = definition.value.edges.find(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') === 'default',
        )
        if (!outgoingEdge) {
            return
        }

        snapshot()
        swapAdjacentNodes(nodeId, outgoingEdge.to)
    }

    function swapAdjacentNodes(firstId: string, secondId: string) {
        const beforeFirst = definition.value.edges.find((edge) => edge.to === firstId)
        const between = definition.value.edges.find(
            (edge) => edge.from === firstId && edge.to === secondId && (edge.handle ?? 'default') === 'default',
        )
        const afterSecond = definition.value.edges.find(
            (edge) => edge.from === secondId && (edge.handle ?? 'default') === 'default',
        )

        if (!between) {
            return
        }

        if (beforeFirst) {
            beforeFirst.to = secondId
        }
        between.from = secondId
        between.to = firstId
        if (afterSecond) {
            afterSecond.from = firstId
        }
    }

    /**
     * Merges a partial config patch into existing node config.
     * For send_message nodes, automatically removes edges whose handle references
     * a button that no longer exists after the patch.
     */
    function updateNodeConfig(nodeId: string, patch: NodeConfig) {
        const node = definition.value.nodes.find((n) => n.id === nodeId)
        if (!node) return

        const merged: NodeConfig = { ...(node.config ?? {}), ...patch }
        for (const key of Object.keys(patch)) {
            if ((patch as Record<string, unknown>)[key] === undefined) {
                delete (merged as Record<string, unknown>)[key]
            }
        }
        node.config = merged

        if (node.type === 'send_message' && Array.isArray(patch.buttons)) {
            const validHandles = new Set(
                (patch.buttons as Array<{ id?: string }>).map((b) => b.id).filter(Boolean),
            )
            definition.value.edges = definition.value.edges.filter((edge) => {
                if (edge.from !== nodeId) return true
                const handle = edge.handle ?? 'default'
                return handle === 'default' || validHandles.has(handle)
            })
        }
    }

    // Mark the flow dirty on any post-hydration mutation of definition or
    // trigger. Cleared again by `setSaveStatus('saved')` and by `init()`.
    watch(
        [definition, trigger],
        () => {
            if (hydrated) {
                isDirty.value = true
            }
        },
        { deep: true },
    )

    return {
        flowId,
        flowName,
        draftVersion,
        publishedVersion,
        definition,
        trigger,
        availableEvents,
        contentBaseLanguage,
        availableLanguages,
        tree,
        saveStatus,
        isDirty,
        activeTab,
        validationResult,
        validationOpen,
        nodesWithErrors,
        undoStack,
        redoStack,
        init,
        setDraftVersion,
        setPublishedVersion,
        setTrigger,
        updateTrigger,
        deleteTrigger,
        setSaveStatus,
        setActiveTab,
        setValidationResult,
        closeValidation,
        undo,
        redo,
        updateNodeConfig,
        insertNode,
        deleteNode,
        moveNodeUp,
        moveNodeDown,
    }
})
