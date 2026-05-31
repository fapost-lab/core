import {defineStore} from 'pinia'
import {computed, ref, watch} from 'vue'
import {nanoid} from 'nanoid'
import {buildTree} from '@builder/utils/buildTree'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
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
  /**
   * Tenant-wide event registry as loaded from the server when the flow
   * opened. `availableEvents` (below) merges this with locally declared
   * `emit_event` nodes so the trigger picker can use brand-new event
   * names before the first publish round-trips them through the
   * tenant registry.
   */
  const tenantEvents = ref<string[]>([])
    const contentBaseLanguage = ref('en')
    const availableLanguages = ref<string[]>([])
    const availableFlows     = ref<Array<{id: string; name: string}>>([])

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const tree = computed(() => buildTree(definition.value.nodes ?? [], definition.value.edges ?? []) as any)

  /**
   * Union of tenant-published events (from server registry) and event
   * names declared on `emit_event` nodes that currently live in this
   * flow's draft. Lets the trigger picker reference a freshly created
   * event before it's been through a publish cycle.
   */
  const availableEvents = computed<string[]>(() => {
    const fromNodes = definition.value.nodes
      .filter((node) => node.type === 'emit_event')
      .map((node) => {
        const cfg = (node.config ?? {}) as Record<string, unknown>
        const name = cfg.event_type ?? cfg.event_name
        return typeof name === 'string' ? name.trim() : ''
      })
      .filter((name) => name !== '')
    return Array.from(new Set([...tenantEvents.value, ...fromNodes])).sort()
  })
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
      tenantEvents.value = Array.isArray(flow.availableEvents) ? flow.availableEvents : []
        contentBaseLanguage.value = flow.contentBaseLanguage ?? 'en'
        availableLanguages.value = Array.isArray(flow.availableLanguages) ? flow.availableLanguages : []
        availableFlows.value     = Array.isArray(flow.availableFlows) ? flow.availableFlows : []
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

    /**
     * Hard-remove a batch of nodes and every edge incident on them.
     *
     * Unlike {@link deleteNode}, this does NOT bridge incoming and outgoing
     * default edges — callers use this when the nodes are unreachable and
     * any inbound edge from a still-living node is itself part of the
     * problem (e.g. a `default` edge from a terminal `send_message`).
     * Bridging in that case would resurrect the broken edge.
     */
    function removeNodes(nodeIds: string[]): boolean {
        if (nodeIds.length === 0) return false

        const targetSet = new Set(nodeIds)
        const present   = definition.value.nodes.some((node) => targetSet.has(node.id))
        if (!present) return false

        snapshot()

        const selectionStore = useSelectionStore()

        definition.value.nodes = definition.value.nodes.filter((node) => !targetSet.has(node.id))
        definition.value.edges = definition.value.edges.filter(
            (edge) => !targetSet.has(edge.from) && !targetSet.has(edge.to),
        )

        if (selectionStore.selectedNodeId && targetSet.has(selectionStore.selectedNodeId)) {
            selectionStore.clear()
        }

        return true
    }

    /**
     * Re-parent the head of an existing chain under a different
     * (sourceNodeId, handle) slot. The rest of the chain travels with
     * it untouched.
     *
     * If the destination handle already has a child, the chain is
     * appended at the END of that destination's existing `default`-tail
     * — nothing gets clobbered. The original incoming edge of the head
     * is removed in the same snapshot so Undo restores both halves.
     */

  /**
   * Re-parent a single node — the moving node detaches from its
   * current slot, the gap it leaves behind is bridged (predecessor
   * → default-successor), and the node lands at the chosen
   * destination as a leaf of the picked branch.
   *
   * Non-default outgoing edges (button branches, condition rules)
   * travel WITH the node since those are part of its identity — a
   * SendMessage with buttons doesn't make sense without its button
   * subtrees, a condition without its rule branches doesn't either.
   *
   * @see moveChain for the variant that takes every descendant —
   *       including the default-successor — along for the ride.
   */
  /**
   * Pass `targetNodeId = null` to promote the moving node to the
   * flow entry — the current entry becomes its default successor.
   */
  function moveNode(movingId: string, targetNodeId: string | null, handle: string): boolean {
    if (!movingId || movingId === targetNodeId) return false

    const movingExists = definition.value.nodes.some((node) => node.id === movingId)
    if (!movingExists) return false

    if (targetNodeId !== null) {
      const targetExists = definition.value.nodes.some((node) => node.id === targetNodeId)
      if (!targetExists) return false
    }

    snapshot()

    const incoming = definition.value.edges.find((edge) => edge.to === movingId)
    const outgoingDefault = definition.value.edges.find(
      (edge) => edge.from === movingId && (edge.handle ?? 'default') === 'default',
    )

    // Root mode (targetNodeId === null): the moving node becomes
    // the new entry; the current root takes its place as the new
    // node's default-successor.
    if (targetNodeId === null) {
      // Detach moving node's incoming + outgoing default. Keep
      // non-default outgoing (button branches travel along).
      definition.value.edges = definition.value.edges.filter((edge) => {
        if (edge.to === movingId) return false
        return !(edge.from === movingId && (edge.handle ?? 'default') === 'default');

      })

      // Bridge old gap so the chain we left keeps flowing.
      if (incoming && outgoingDefault) {
        definition.value.edges.push({
          id: nanoid(10),
          from: incoming.from,
          to: outgoingDefault.to,
          handle: incoming.handle ?? 'default',
        })
      }

      // Find the current entry (root with no incoming edge,
      // excluding the moving node itself) and chain it under
      // the new entry.
      const incomingByTarget = new Set(definition.value.edges.map((edge) => edge.to))
      const currentRoot = definition.value.nodes.find(
        (n) => n.id !== movingId && !incomingByTarget.has(n.id),
      )
      if (currentRoot) {
        definition.value.edges.push({
          id: nanoid(10),
          from: movingId,
          to: currentRoot.id,
          handle: 'default',
        })
      }

      return true
    }

    // Existing occupant of the destination slot — we splice the
    // moving node in front of it, so this becomes the moving
    // node's new default-successor.
    const destOccupant = definition.value.edges.find(
      (edge) => edge.from === targetNodeId && (edge.handle ?? 'default') === handle,
    )

    // Detach: drop the broken incoming, the outgoing default edge
    // (its target becomes the gap-fill), and the destination slot's
    // existing edge (we're replacing it with the spliced chain).
    // Non-default outgoing edges of the moving node stay attached.
    definition.value.edges = definition.value.edges.filter((edge) => {
      if (edge.to === movingId) return false
      if (edge.from === movingId && (edge.handle ?? 'default') === 'default') return false
      return !(edge.from === targetNodeId && (edge.handle ?? 'default') === handle);

    })

    // Bridge the original gap so the linear chain keeps flowing.
    if (incoming && outgoingDefault) {
      definition.value.edges.push({
        id: nanoid(10),
        from: incoming.from,
        to: outgoingDefault.to,
        handle: incoming.handle ?? 'default',
      })
    }

    // Splice: dest → movingNode at the picked handle.
    definition.value.edges.push({
      id: nanoid(10),
      from: targetNodeId,
      to: movingId,
      handle,
    })

    // If the slot was occupied, reattach the old occupant as the
    // moving node's default-successor — the user wanted the node
    // to live AT this slot, not past everything that was there.
    if (destOccupant) {
      definition.value.edges.push({
        id: nanoid(10),
        from: movingId,
        to: destOccupant.to,
        handle: 'default',
      })
    }

    return true
  }

  /**
   * Pass `sourceNodeId = null` to promote the chain head to the flow
   * entry — current entry latches onto the chain's tail.
   */
  function moveChain(headNodeId: string, sourceNodeId: string | null, handle: string): boolean {
    if (!headNodeId || headNodeId === sourceNodeId) return false

        const headExists = definition.value.nodes.some((node) => node.id === headNodeId)
    if (!headExists) return false

    if (sourceNodeId !== null) {
      const sourceExists = definition.value.nodes.some((node) => node.id === sourceNodeId)
      if (!sourceExists) return false
    }

    // Walk the moving chain's own default tail — that's where the
    // destination's previous occupant will reattach after splice.
    // Stops at the first cycle or terminal so we don't follow
    // graphs the engine wouldn't have followed either.
    let chainTailId = headNodeId
    const chainVisited = new Set<string>([headNodeId])
        while (true) {
            const next = definition.value.edges.find(
              (edge) => edge.from === chainTailId && (edge.handle ?? 'default') === 'default',
            )
            if (!next) break
          if (chainVisited.has(next.to)) break
          chainVisited.add(next.to)
          chainTailId = next.to
        }

    // Refuse if the destination sits anywhere inside the chain we
    // would otherwise relocate — that's a cycle.
    if (sourceNodeId !== null && chainVisited.has(sourceNodeId)) return false

        snapshot()

    // Root mode: drop the chain's inbound edge and chain the
    // existing entry under the chain's tail.
    if (sourceNodeId === null) {
      definition.value.edges = definition.value.edges.filter(
        (edge) => edge.to !== headNodeId,
      )
      const incomingByTarget = new Set(definition.value.edges.map((edge) => edge.to))
      const currentRoot = definition.value.nodes.find(
        (n) => !chainVisited.has(n.id) && !incomingByTarget.has(n.id),
      )
      if (currentRoot) {
        definition.value.edges.push({
          id: nanoid(10),
          from: chainTailId,
          to: currentRoot.id,
          handle: 'default',
        })
      }
      return true
    }

    const destOccupant = definition.value.edges.find(
      (edge) => edge.from === sourceNodeId && (edge.handle ?? 'default') === handle,
        )

    // Drop the broken inbound edge to the chain head (if any) and
    // the destination slot's existing edge — we're splicing the
    // chain in between.
    definition.value.edges = definition.value.edges.filter((edge) => {
      if (edge.to === headNodeId) return false

      return !(edge.from === sourceNodeId && (edge.handle ?? 'default') === handle);
    })

    // Splice: dest → chain head at the picked handle.
        definition.value.edges.push({
            id: nanoid(10),
          from: sourceNodeId,
            to: headNodeId,
          handle,
        })

    // If the slot had a child, reattach it after the chain's tail.
    if (destOccupant) {
      definition.value.edges.push({
        id: nanoid(10),
        from: chainTailId,
        to: destOccupant.to,
        handle: 'default',
      })
    }

        return true
    }

    function moveNodeUp(nodeId: string) {
        const node = definition.value.nodes.find((n) => n.id === nodeId)
        if (!node) return
        // Terminal nodes (end, send_message with buttons / reply keyboard)
        // must always sit at the tail of their chain — lifting them would
        // strand whatever was last, see `nodeMustBeLast` for the contract.
        if (nodeMustBeLast(node)) return

        const incomingEdge = definition.value.edges.find((edge) => edge.to === nodeId)
        if (!incomingEdge) {
            return
        }

        snapshot()
        swapAdjacentNodes(incomingEdge.from, nodeId)
    }

    function moveNodeDown(nodeId: string) {
        const outgoingEdge = definition.value.edges.find(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') === 'default',
        )
        if (!outgoingEdge) {
            return
        }

        // Refuse to demote a terminator by swapping it backwards — see
        // `nodeMustBeLast` for the set of types that have to stay last.
        const successor = definition.value.nodes.find((n) => n.id === outgoingEdge.to)
        if (successor && nodeMustBeLast(successor)) return

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
        availableFlows,
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
        removeNodes,
      moveNode,
        moveChain,
        moveNodeUp,
        moveNodeDown,
    }
})
