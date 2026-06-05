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

export interface BuilderFlowPayload {
    flowId: string
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
}

export type SaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'error'

/**
 * Variable descriptor used by nodes that persist user input — Input,
 * SendMessage save_to, Assign. The component VariableStorageEditor edits
 * this shape; nodes compile it into their own JSON snapshot.
 */
export type VariableType =
    | 'text' | 'number' | 'phone' | 'email' | 'contact'
    | 'select' | 'confirm' | 'file' | 'photo' | 'location' | 'date' | 'json'

export type VariableStorage = 'contact' | 'session'

export interface Variable {
    name: string
    type: VariableType
    storage: VariableStorage
    group: string | null
}

export type ActiveTab = 'builder' | 'content'

export interface ValidationError {
    path: string
    message: string
}

export interface ValidationResult {
    valid: boolean
    errors?: ValidationError[]
}
