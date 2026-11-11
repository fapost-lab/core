/**
 * Pure helpers for flow templates: the `template` reference node (a flow side),
 * the `template_output` node (a template side) and the kind-aware palette.
 *
 * A `template` node keeps a SNAPSHOT of the template's outputs in
 * `config.outputs`; its handles are exactly those output names. The snapshot is
 * refreshed from the published template whenever a flow opens.
 */

import type {AvailableTemplate, FlowEdge, FlowKind, FlowNode, NodeTypePayload, TemplateOutput} from '@builder/dto/types'

export const TEMPLATE_NODE_TYPE = 'template'
export const TEMPLATE_OUTPUT_NODE_TYPE = 'template_output'

/** Normalise raw `config.outputs` into well-formed `{name, label}` pairs. */
export function readTemplateOutputs(config: Record<string, unknown> | null | undefined): TemplateOutput[] {
    const raw = config?.outputs
    if (!Array.isArray(raw)) {
        return []
    }

    return (raw as Array<Record<string, unknown>>)
        .filter((o) => o !== null && typeof o === 'object' && typeof o.name === 'string' && o.name !== '')
        .map((o) => ({
            name: o.name as string,
            label: typeof o.label === 'string' && o.label !== '' ? o.label : null,
        }))
}

/** Human label of an output: its label, else its name. */
export function templateOutputLabel(output: TemplateOutput): string {
    return output.label !== null && output.label !== '' ? output.label : output.name
}

/** Config patch written when a template is picked in the config panel. */
export function templateSelectionPatch(template: AvailableTemplate): {template_flow_id: string; outputs: TemplateOutput[]} {
    return {
        template_flow_id: template.id,
        outputs: template.outputs.map((o) => ({name: o.name, label: o.label ?? null})),
    }
}

/** Edges leaving `nodeId` through a handle that is not one of `outputs`. */
export function edgesToDropAfterReselect(edges: FlowEdge[], nodeId: string, outputs: TemplateOutput[]): FlowEdge[] {
    const names = new Set(outputs.map((o) => o.name))

    return edges.filter((edge) => edge.from === nodeId && !names.has(edge.handle ?? 'default'))
}

/**
 * Refresh `config.outputs` of every `template` node whose template is available
 * (mutates the nodes). Never touches edges: an edge to a vanished output is for
 * validate/publish to report. Returns true only when something actually changed,
 * so the caller can leave the draft clean otherwise.
 */
export function refreshTemplateSnapshots(nodes: FlowNode[], templates: AvailableTemplate[]): boolean {
    let changed = false

    for (const node of nodes) {
        if (node.type !== TEMPLATE_NODE_TYPE) {
            continue
        }

        const template = templates.find((t) => t.id === node.config?.template_flow_id)
        if (!template) {
            continue
        }

        const fresh = templateSelectionPatch(template).outputs
        if (JSON.stringify(readTemplateOutputs(node.config)) === JSON.stringify(fresh)
            && Array.isArray(node.config?.outputs)) {
            continue
        }

        node.config = {...(node.config ?? {}), outputs: fresh}
        changed = true
    }

    return changed
}

/**
 * Node types offered by the palette for a flow kind: auto-managed types
 * (`palette === false`) never; `template_output` only inside a template;
 * `template` only in a flow (templates do not nest).
 */
export function paletteNodeTypes(types: NodeTypePayload[], kind: FlowKind): NodeTypePayload[] {
    return types.filter((n) => {
        if (n.palette === false) return false
        if (n.type === TEMPLATE_OUTPUT_NODE_TYPE) return kind === 'template'
        if (n.type === TEMPLATE_NODE_TYPE) return kind === 'flow'

        return true
    })
}
