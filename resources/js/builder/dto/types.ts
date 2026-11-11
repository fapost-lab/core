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

/** Discriminated union: either a delete marker or a full trigger config. */
export type BuilderTriggerPayload =
    | { _delete: true }
    | {
          _delete?: false
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

export interface BuilderFlowPayload {
    flowId: string
    kind?: FlowKind
    availableTemplates?: AvailableTemplate[]
    usedBy?: Array<{id: string; name: string}>
    name: string
    draftVersion: number
    publishedVersion?: number | null
    definition?: unknown
    trigger?: BuilderTriggerPayload | null
    availableEvents?: string[]
    availableLanguages?: string[]
    contentBaseLanguage?: string
    publishedAt?: string | null
    availableFlows?: Array<{id: string; name: string}>
    availableActions?: string[]
    availableCountries?: Array<{value: string; label: string}>
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
}

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
