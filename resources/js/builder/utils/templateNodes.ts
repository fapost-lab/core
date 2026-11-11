/**
 * Pure helpers for flow templates: the `template` reference node (a flow side),
 * the `template_output` node (a template side) and the kind-aware palette.
 *
 * A `template` node keeps a SNAPSHOT of the template's outputs in
 * `config.outputs`; its handles are exactly those output names. The snapshot is
 * refreshed from the published template whenever a flow opens.
 */

import type {
    AvailableTemplate,
    FlowEdge,
    FlowKind,
    FlowNode,
    NodeTypePayload,
    TemplateOutput,
    TemplateParameter,
} from '@builder/dto/types'

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

/**
 * Normalise raw `config.parameters` into well-formed snapshot entries, built in
 * a fixed key order (jsonb re-orders object keys, and the snapshot is compared
 * through JSON.stringify).
 */
export function readTemplateParameters(config: Record<string, unknown> | null | undefined): TemplateParameter[] {
    const raw = config?.parameters
    if (!Array.isArray(raw)) {
        return []
    }

    return (raw as Array<Record<string, unknown>>)
        .filter((p) => p !== null && typeof p === 'object'
            && typeof p.key === 'string' && p.key !== ''
            && typeof p.node_type === 'string' && typeof p.field === 'string')
        .map((p) => normaliseParameter(p))
}

function normaliseParameter(p: Record<string, unknown>): TemplateParameter {
    return {
        key: p.key as string,
        label: typeof p.label === 'string' && p.label !== '' ? p.label : null,
        node_type: p.node_type as string,
        node_version: typeof p.node_version === 'number' ? p.node_version : Number(p.node_version ?? 1) || 1,
        field: p.field as string,
    }
}

/**
 * True when a parameter value means "keep the template's own value": absent,
 * null, a blank string, an empty array, or a language map without any
 * non-blank string. `false` and `0` are real values.
 */
export function isEmptyParameterValue(value: unknown): boolean {
    if (value === undefined || value === null) return true
    if (typeof value === 'string') return value.trim() === ''
    if (Array.isArray(value)) return value.length === 0
    if (typeof value === 'object') {
        // Same rule as the server: empty only when every entry is a blank text.
        return Object.values(value as Record<string, unknown>).every((v) => typeof v === 'string' && v.trim() === '')
    }

    return false
}

/** Schema field types a template author may expose as a parameter. */
export const EXPOSABLE_FIELD_TYPES = [
    'string', 'text', 'number', 'boolean', 'enum', 'enum-cards', 'duration', 'array', 'state-picker', 'flow-picker',
]

/** Node types whose fields can never be parameters. */
export const NON_PARAMETERIZABLE_NODE_TYPES = ['template', 'template_output', 'loop', 'loop_end']

export interface ExposableField {
    key: string
    label: string
    type: string
}

/** Top-level fields of a handler `config_schema` that can be exposed as parameters. */
export function exposableFields(configSchema: Record<string, unknown> | null | undefined, nodeType: string): ExposableField[] {
    if (!configSchema || NON_PARAMETERIZABLE_NODE_TYPES.includes(nodeType)) {
        return []
    }

    const fields: ExposableField[] = []
    for (const [key, raw] of Object.entries(configSchema)) {
        if (key === 'default_config' || raw === null || typeof raw !== 'object' || Array.isArray(raw)) continue
        const schema = raw as Record<string, unknown>
        const type = typeof schema.type === 'string' ? schema.type : ''
        if (!EXPOSABLE_FIELD_TYPES.includes(type)) continue
        fields.push({key, label: typeof schema.label === 'string' && schema.label !== '' ? schema.label : key, type})
    }

    return fields
}

/** Key pattern shared with the server. */
export const PARAMETER_KEY_PATTERN = /^[a-z][a-z0-9_]{0,63}$/

/** Human label of an output: its label, else its name. */
export function templateOutputLabel(output: TemplateOutput): string {
    return output.label !== null && output.label !== '' ? output.label : output.name
}

/** Config patch written when a template is picked in the config panel. */
export function templateSelectionPatch(template: AvailableTemplate): {
    template_flow_id: string
    outputs: TemplateOutput[]
    parameters: TemplateParameter[]
} {
    return {
        template_flow_id: template.id,
        outputs: template.outputs.map((o) => ({name: o.name, label: o.label ?? null})),
        // `default` is deliberately dropped: it is display-only and must never reach the draft.
        parameters: (template.parameters ?? []).map((p) => normaliseParameter(p as unknown as Record<string, unknown>)),
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

        const fresh = templateSelectionPatch(template)
        const outputsSame = JSON.stringify(readTemplateOutputs(node.config)) === JSON.stringify(fresh.outputs)
            && Array.isArray(node.config?.outputs)
        // A missing key equals an empty list: nodes saved before parameters existed stay clean.
        const parametersSame = JSON.stringify(readTemplateParameters(node.config)) === JSON.stringify(fresh.parameters)
        if (outputsSame && parametersSame) {
            continue
        }

        node.config = {...(node.config ?? {}), outputs: fresh.outputs, parameters: fresh.parameters}
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

/**
 * Plain-text rendering of a parameter value for placeholders and hints. A
 * language map shows the base language (else the first non-blank text).
 */
export function parameterValueText(value: unknown, baseLanguage: string): string {
    if (value === undefined || value === null) return ''
    if (typeof value === 'string') return value
    if (typeof value === 'number' || typeof value === 'boolean') return String(value)
    if (Array.isArray(value)) return value.map((v) => parameterValueText(v, baseLanguage)).join(', ')
    if (typeof value === 'object') {
        const map = value as Record<string, unknown>
        const base = map[baseLanguage]
        if (typeof base === 'string' && base.trim() !== '') return base
        const first = Object.values(map).find((v) => typeof v === 'string' && v.trim() !== '')

        return typeof first === 'string' ? first : ''
    }

    return ''
}

/**
 * A translatable parameter's value after its base-language text is edited in
 * the panel. Other languages stay; a blank base-language entry is kept (not
 * dropped), because publishing fills a blank language from the template's text
 * for that language, while a missing one would resolve to another language.
 */
export function withBaseLanguageText(existing: unknown, baseLanguage: string, text: string): Record<string, unknown> {
    const map = existing !== null && typeof existing === 'object' && !Array.isArray(existing)
        ? existing as Record<string, unknown>
        : typeof existing === 'string' && existing.trim() !== '' ? {[baseLanguage]: existing} : {}

    return {...map, [baseLanguage]: text}
}

/**
 * The reference node's `parameter_values` after one parameter is set: an
 * empty value (see isEmptyParameterValue) removes the key, so the template's
 * value stays; `undefined` is returned when nothing is left.
 */
export function nextParameterValues(
    values: Record<string, unknown>,
    key: string,
    value: unknown,
): Record<string, unknown> | undefined {
    const next = {...values}
    if (isEmptyParameterValue(value)) {
        delete next[key]
    } else {
        next[key] = value
    }

    return Object.keys(next).length > 0 ? next : undefined
}
