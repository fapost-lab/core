/**
 * Compile / decompile helpers for the {@link Variable} descriptor used by
 * Input, Assign and similar nodes that persist user input.
 *
 * Generic — not tied to any specific node type. Nodes wrap these helpers
 * inside their override config component to translate between UI shape and
 * the JSON snapshot stored in `node.config`.
 *
 * Backwards compatibility: legacy flows store a string `save_to` path
 * (e.g. "flow.code", "contact.survey_q1.score"). `decompileLegacySaveTo`
 * parses that path into a `Variable`. The compiler only writes the new
 * `variable` shape — coexistence with `save_to` is rejected by the backend
 * validator, so callers must explicitly drop the legacy key on save.
 */

import type {Variable, VariableStorage, VariableType} from '@builder/dto/types'

/** JSON shape persisted in `node.config.variable`. UI `type` is metadata. */
export interface CompiledVariable {
    name:    string
    type:    VariableType
    storage: VariableStorage
    group:   string | null
}

/** Build the JSON snapshot stored under `node.config.variable`. */
export function compileVariable(variable: Variable): CompiledVariable {
    return {
        name:    variable.name,
        type:    variable.type,
        storage: variable.storage,
        group:   variable.storage === 'contact' ? variable.group : null,
    }
}

/**
 * Parse a legacy `save_to` string into a `Variable`. Always returns a
 * usable descriptor — never throws — because we want the editor to load
 * even malformed legacy paths and let the user fix them on save.
 *
 * Recognized shapes:
 *   - `flow.foo`              → session, no group
 *   - `contact.foo`           → contact, no group
 *   - `contact.bar.foo`       → contact, group=bar
 *   - `contact.a.b.c`         → contact, group=a, name=c (depth>1, warn)
 *   - `foo` (no prefix)       → session, no group (historical default)
 */
export function decompileLegacySaveTo(saveTo: string): Variable {
    const fallbackType: VariableType = 'text'
    const trimmed = (saveTo ?? '').trim()

    if (trimmed === '') {
        return { name: '', type: fallbackType, storage: 'session', group: null }
    }

    const parts = trimmed.split('.').filter((p) => p !== '')

    // No prefix — historical default was session (`flow.*`).
    if (parts.length === 1) {
        return { name: parts[0]!, type: fallbackType, storage: 'session', group: null }
    }

    const [head, ...rest] = parts as [string, ...string[]]

    if (head === 'flow') {
        // `flow.foo` is the canonical session shape; deeper nesting is
        // unusual but we collapse to the leaf name.
        if (rest.length > 1) {
            // eslint-disable-next-line no-console -- intentional warn for legacy auto-fix
            console.warn(`[variableCompiler] legacy save_to "${saveTo}" has depth>1 under flow.*; collapsing to leaf "${rest.at(-1)}"`)
        }
        return { name: rest.at(-1)!, type: fallbackType, storage: 'session', group: null }
    }

    if (head === 'contact') {
        if (rest.length === 1) {
            return { name: rest[0]!, type: fallbackType, storage: 'contact', group: null }
        }
        if (rest.length === 2) {
            return { name: rest[1]!, type: fallbackType, storage: 'contact', group: rest[0]! }
        }
        // depth > 2 (contact.a.b.c) — keep first segment as group, leaf as name.
        // eslint-disable-next-line no-console -- intentional warn for legacy auto-fix
        console.warn(`[variableCompiler] legacy save_to "${saveTo}" has depth>1 group; using group="${rest[0]}", name="${rest.at(-1)}"`)
        return { name: rest.at(-1)!, type: fallbackType, storage: 'contact', group: rest[0]! }
    }

    // Unknown prefix — treat the whole path as a session variable name leaf.
    return { name: parts.at(-1)!, type: fallbackType, storage: 'session', group: null }
}

/**
 * Read a `Variable` from a node config supporting both formats. Pulls the
 * `type` metadata from the existing `variable` block when present, otherwise
 * synthesizes from `expected_type` (Input) or falls back to "text".
 */
export function decodeInputVariable(config: Record<string, unknown> | null | undefined): Variable {
    const cfg = config ?? {}
    const raw = cfg.variable

    if (raw && typeof raw === 'object') {
        const obj = raw as Record<string, unknown>
        const name    = typeof obj.name === 'string' ? obj.name : ''
        const storage = obj.storage === 'session' ? 'session' : 'contact'
        const group   = typeof obj.group === 'string' && obj.group !== '' ? obj.group : null
        const type    = typeof obj.type === 'string'
            ? (obj.type as VariableType)
            : (typeof cfg.expected_type === 'string' ? (cfg.expected_type as VariableType) : 'text')

        return {
            name,
            type,
            storage,
            group: storage === 'contact' ? group : null,
        }
    }

    const saveTo = typeof cfg.save_to === 'string' ? cfg.save_to : ''
    const decoded = decompileLegacySaveTo(saveTo)

    if (typeof cfg.expected_type === 'string' && cfg.expected_type !== '') {
        decoded.type = cfg.expected_type as VariableType
    }

    return decoded
}

/** A single Assign node operation: which variable to write and the value template. */
export interface AssignOperation {
    variable: Variable
    value:    string
}

/**
 * Decode Assign node config into a list of operations supporting both shapes:
 *   - new: `operations: [{ variable, value }, ...]`
 *   - legacy: `{ target: 'flow' | 'contact', key, value }`
 *
 * Returns an empty array when no operations and no legacy target/key/value are
 * present — caller is expected to seed at least one empty operation for UX.
 */
export function decodeAssignOperations(
    config: Record<string, unknown> | null | undefined,
): AssignOperation[] {
    const cfg = config ?? {}
    const raw = cfg.operations

    if (Array.isArray(raw)) {
        const out: AssignOperation[] = []
        for (const entry of raw) {
            if (!entry || typeof entry !== 'object') {
                continue
            }
            const obj    = entry as Record<string, unknown>
            const varRaw = obj.variable as Record<string, unknown> | undefined
            const value  = typeof obj.value === 'string' ? obj.value : ''

            if (!varRaw || typeof varRaw !== 'object') {
                continue
            }

            const name    = typeof varRaw.name === 'string' ? varRaw.name : ''
            const storage = varRaw.storage === 'session' ? 'session' : 'contact'
            const group   = typeof varRaw.group === 'string' && varRaw.group !== '' ? varRaw.group : null
            const type    = typeof varRaw.type === 'string' ? (varRaw.type as VariableType) : 'text'

            out.push({
                variable: {
                    name,
                    type,
                    storage,
                    group: storage === 'contact' ? group : null,
                },
                value,
            })
        }
        return out
    }

    // Legacy shape — single operation.
    const target = typeof cfg.target === 'string' ? cfg.target : ''
    const key    = typeof cfg.key === 'string' ? cfg.key : ''
    const value  = typeof cfg.value === 'string' ? cfg.value : ''

    if (target === '' && key === '' && value === '') {
        return []
    }

    return [{
        variable: {
            name:    key,
            type:    'text',
            storage: target === 'contact' ? 'contact' : 'session',
            group:   null,
        },
        value,
    }]
}

/**
 * Map legacy SendMessage `save_to_type` (string|number|boolean) onto the
 * canonical {@link VariableType} taxonomy used by VariableStorageEditor.
 */
function mapLegacySaveToType(legacy: string | undefined): VariableType {
    switch (legacy) {
        case 'number':  return 'number'
        case 'boolean': return 'confirm'
        case 'string':
        default:        return 'text'
    }
}

/**
 * Read the answer-storage descriptor from a SendMessage node config.
 *
 * Returns null when the user has not configured any save target — callers
 * should treat that as "do not persist button presses". Recognises both
 * the new `save_to_variable` block and the legacy `save_to` + `save_to_type`
 * pair (legacy was always written under `flow.*`, hence storage='session').
 */
export function decodeSendMessageVariable(
    config: Record<string, unknown> | null | undefined,
): Variable | null {
    const cfg = config ?? {}
    const raw = cfg.save_to_variable

    if (raw && typeof raw === 'object') {
        const obj = raw as Record<string, unknown>
        const name    = typeof obj.name === 'string' ? obj.name : ''
        const storage = obj.storage === 'session' ? 'session' : 'contact'
        const group   = typeof obj.group === 'string' && obj.group !== '' ? obj.group : null
        const type    = typeof obj.type === 'string' ? (obj.type as VariableType) : 'text'

        return {
            name,
            type,
            storage,
            group: storage === 'contact' ? group : null,
        }
    }

    const saveTo = typeof cfg.save_to === 'string' ? cfg.save_to.trim() : ''
    if (saveTo === '') {
        return null
    }

    const legacyType = typeof cfg.save_to_type === 'string' ? cfg.save_to_type : 'string'

    return {
        name:    saveTo,
        type:    mapLegacySaveToType(legacyType),
        storage: 'session',
        group:   null,
    }
}

/**
 * Reverse mapping for {@link mapLegacySaveToType} — used to keep
 * KeyboardListEditor's per-button value hint working while the underlying
 * storage descriptor switched to {@link VariableType}.
 */
export function variableTypeToLegacySaveToType(type: VariableType | undefined): string {
    switch (type) {
        case 'number':  return 'number'
        case 'confirm': return 'boolean'
        default:        return 'string'
    }
}
