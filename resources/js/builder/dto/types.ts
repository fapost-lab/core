/** Unstable node config — will be replaced with discriminated union types per node type as schemas stabilize. */
// TODO: replace with discriminated union when individual node config schemas stabilize
export type NodeConfig = Record<string, unknown>

export interface FlowNode {
    id: string
    type: string
    version: number
    label?: string
    config: NodeConfig
    outputs?: Record<string, { next?: string } | null>
    /** Template drafts only: config fields of this node exposed as template parameters. */
    parameters?: TemplateParameterDeclaration[]
}

export interface FlowEdge {
    id: string
    from: string
    to: string
    handle?: string
}

export interface FlowDefinition {
    nodes: FlowNode[]
    edges: FlowEdge[]
}

export type TriggerType = 'message' | 'schedule' | 'webhook' | 'api' | 'event'

/** One trigger of a flow; the id is a ULID in RFC 4122 form, minted by the builder (utils/ulid.ts), that becomes its flow_triggers primary key on publish. */
export interface BuilderTrigger {
    id: string
    type: TriggerType
    is_active: boolean
    priority: number
    config: Record<string, unknown>
}

export type FlowKind = 'flow' | 'template'

export interface TemplateOutput {
    name: string
    label: string | null
}

/** A parameter as the template author declares it on an inner node of a template draft. */
export interface TemplateParameterDeclaration {
    key: string
    label: string | null
    field: string
}

/** A parameter as a `template` reference node snapshots it in `config.parameters`. */
export interface TemplateParameter extends TemplateParameterDeclaration {
    node_type: string
    node_version: number
}

/** A parameter as the builder payload offers it; `default` is the template's current value (display only). */
export interface AvailableTemplateParameter extends TemplateParameter {
    default?: unknown
}

export interface AvailableTemplate {
    id: string
    name: string
    version: number
    outputs: TemplateOutput[]
    parameters?: AvailableTemplateParameter[]
}

export interface WebhookEndpointInfo {
    url: string
    has_secret: boolean
    secret_rotated_at: string | null
}

/** What run admission knows about a live trigger: paused by the run rate breaker, and its refused runs. */
export interface TriggerStateInfo {
    paused_at: string | null
    paused_reason: string | null
    refused_count: number
    last_refused_at: string | null
    last_refusal_reason: string | null
}

export interface BuilderFlowPayload {
    flowId: string
    kind?: FlowKind
    availableTemplates?: AvailableTemplate[]
    usedBy?: Array<{id: string; name: string}>
    name: string
    draftVersion: number
    publishedVersion?: number | null
    definition?: unknown
    triggers?: BuilderTrigger[]
    availableEvents?: string[]
    /** Webhook URLs that already exist, keyed by lower-cased trigger id. */
    webhookEndpoints?: Record<string, WebhookEndpointInfo>
    /** Admission state of the live triggers that have one, keyed by lower-cased trigger id. */
    triggerStates?: Record<string, TriggerStateInfo>
    availableLanguages?: string[]
    contentBaseLanguage?: string
    publishedAt?: string | null
    availableFlows?: Array<{id: string; name: string}>
    availableActions?: string[]
    availableCountries?: Array<{value: string; label: string}>
    /** The tenant is stopped and the user may only look: no editing, no saving, no publishing. */
    readOnly?: boolean
    /** The layout the signed-in staff user chose for the builder (`users.builder_mode`); `tree` until they switch. */
    builderMode?: BuilderMode
}

/** The builder's two layouts over the same draft: the structure tree with its sequence, or one graph of the whole flow. */
export type BuilderMode = 'tree' | 'graph'

/** The operator's notice for a stopped tenant, as the shared `accessState` prop and a 423 answer carry it. */
export interface ReadOnlyNotice {
    title: string
    message: string | null
    actionLabel: string | null
    actionUrl: string | null
}

export interface NodeTypePayload {
    type: string
    version: number
    label: string
    category: string
    config_schema: {
        default_config?: Record<string, unknown>
        [key: string]: unknown
    }
    /** false → type is registered but hidden from the insertable palette (auto-managed). */
    palette?: boolean
    /** Palette group the type belongs to; absent in older responses (derived from `category`). */
    group?: NodeGroup
    /** false → the owning Solution is turned off for this tenant: the node renders read-only. */
    available?: boolean
    /** Owning Solution; null for Core types. */
    solution?: { id: string, name: string } | null
}

export interface NodeGroup {
    key: string
    label: string
    /** Solution id for a Solution's own group, null for a Core group. */
    owner: string | null
}

/** State namespace of a Solution active for the tenant (`feedback.*`); keys inside it are free-form. */
export interface StateNamespaceEntry {
    key: string
    owner: string
}

export type SaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'error'

/**
 * Variable descriptor used by nodes that persist user input — Input,
 * SendMessage save_to, Assign. The component VariableStorageEditor edits
 * this shape; nodes compile it into their own JSON snapshot.
 */
export type VariableType =
    | 'text' | 'number' | 'boolean' | 'phone' | 'email' | 'contact'
    | 'select' | 'confirm' | 'file' | 'photo' | 'location' | 'date' | 'json' | 'array'

export type VariableStorage = 'contact' | 'session'

export interface Variable {
    name: string
    /** Element / scalar type. When {@link isList} is true this is the array's item type. */
    type: VariableType
    storage: VariableStorage
    group: string | null
    /**
     * UI flag: when true the variable accumulates a list (append semantics).
     * Compiles to backend `type: 'array'` + `properties.item_type = type`.
     */
    isList?: boolean
    /**
     * List variables saved to the contact only: how many elements the list keeps before the oldest drops out.
     * Absent means the platform default. Compiles to `properties.max_size`.
     */
    maxSize?: number | null
}

/** Bounds of a list's `max_size`, mirrored from the backend (`Variable::LIST_*_SIZE`). */
export const LIST_DEFAULT_SIZE = 100
export const LIST_MIN_SIZE = 1
export const LIST_MAX_SIZE = 1000

export type ActiveTab = 'builder' | 'content'

export interface ValidationError {
    path: string
    message: string
}

export interface ValidationResult {
    valid: boolean
    errors?: ValidationError[]
    /** Advice only (e.g. a channel of the assistant lacks a capability the flow uses): never blocks publishing. */
    warnings?: ValidationError[]
}
