/**
 * Compile / decompile helpers for the Branch (Condition) operand `left` block.
 *
 * The new operand picker writes a structured shape into each rule:
 *
 *   {
 *     "left": { "ref": "user_variable", "variable": { name, storage, group } },
 *     "operator": "eq", "value": "...", "handle": "yes"
 *   }
 *
 *   {
 *     "left": { "ref": "source", "source": "rag", "field": "found" },
 *     "operator": "eq", "value": true, "handle": "yes"
 *   }
 *
 * Legacy flows store `left` as a string path (or a top-level `check` in the
 * node config). The decompiler maps both back into a UI state object so the
 * picker can drive its dropdowns; the compiler always emits the new shape.
 */

import type {Variable} from '@builder/dto/types'
import type {PickerVariable} from '@builder/composables/useFlowVariables'

export type BranchOperandMode = 'user_variable' | 'source'

export interface BranchOperandUiState {
    mode: BranchOperandMode
    /** When mode='user_variable' — the chosen variable descriptor (or null when not yet picked). */
    variable: Variable | null
    /** When mode='source' — the source kind ('contact', 'rag', 'call', 'system' or 'module.<name>'). */
    source: string | null
    /** When mode='source' — the field name within the source. */
    field: string | null
    /** Raw legacy path that could not be mapped (UI shows "unknown source" warning). */
    rawPath: string | null
}

export interface CompiledLeft {
    ref:       BranchOperandMode
    variable?: { name: string; storage: string; group: string | null }
    source?:   string
    field?:    string
}

/** Build the JSON snapshot stored under `rule.left`. */
export function compileLeft(state: BranchOperandUiState): CompiledLeft | null {
    if (state.mode === 'user_variable') {
        if (!state.variable || state.variable.name.trim() === '') {
            return null
        }
        return {
            ref: 'user_variable',
            variable: {
                name:    state.variable.name,
                storage: state.variable.storage,
                group:   state.variable.storage === 'contact' ? state.variable.group : null,
            },
        }
    }
    if (!state.source || !state.field) {
        return null
    }
    return {
        ref:    'source',
        source: state.source,
        field:  state.field,
    }
}

/** Source prefixes that always indicate source mode (never user_variable). */
const SOURCE_PREFIXES = ['rag.', 'call.', 'system.']

function emptyState(): BranchOperandUiState {
    return { mode: 'user_variable', variable: null, source: null, field: null, rawPath: null }
}

function stripBraces(raw: string): string {
    const trimmed = raw.trim()
    const match   = trimmed.match(/^\{\{\s*(.+?)\s*\}\}$/)
    return match ? match[1]! : trimmed
}

function findVariableByPath(path: string, userVars: PickerVariable[]): Variable | null {
    const hit = userVars.find((v) => v.path === path)
    if (!hit) {
        return null
    }
    if (hit.source.kind === 'temporary') {
        return { name: hit.pathSegments.at(-1)!, type: 'text', storage: 'session', group: null }
    }
    if (hit.source.kind === 'contact-profile') {
        return {
            name:    hit.pathSegments.at(-1)!,
            type:    'text',
            storage: 'contact',
            group:   hit.group,
        }
    }
    return null
}

function pathToSourceField(path: string): { source: string; field: string } | null {
    const segments = path.split('.').filter((p) => p !== '')
    if (segments.length < 2) {
        return null
    }

    if (segments[0] === 'module' && segments.length >= 3) {
        const source = `module.${segments[1]}`
        const field  = segments.slice(2).join('.')
        return { source, field }
    }

    if (segments[0] === 'contact' && segments.length === 2) {
        return { source: 'contact', field: segments[1]! }
    }

    if (segments[0] === 'contact' && segments.length >= 3) {
        // contact.<group>.<field> — group is part of field path for source mode
        return { source: 'contact', field: segments.slice(1).join('.') }
    }

    return { source: segments[0]!, field: segments.slice(1).join('.') }
}

/**
 * Parse `rule.left` into UI state. Accepts:
 *  - new structured shape `{ ref: 'user_variable' | 'source', ... }`
 *  - legacy string path `flow.foo`, `{{flow.foo}}`, `rag.found`, `module.hr.x`
 *  - null/undefined → empty user_variable mode (default for new rules)
 */
export function decompileLeft(
    left: unknown,
    userVars: PickerVariable[],
): BranchOperandUiState {
    if (!left) {
        return emptyState()
    }

    if (typeof left === 'object') {
        const obj = left as Record<string, unknown>
        const ref = obj.ref

        if (ref === 'user_variable') {
            const v = (obj.variable && typeof obj.variable === 'object'
                ? obj.variable
                : { name: obj.name, storage: obj.storage, group: obj.group }) as Record<string, unknown>

            const name    = typeof v.name === 'string' ? v.name : ''
            const storage = v.storage === 'contact' ? 'contact' : 'session'
            const group   = typeof v.group === 'string' && v.group !== '' ? v.group : null

            if (name === '') {
                return emptyState()
            }

            return {
                mode:     'user_variable',
                variable: { name, type: 'text', storage, group: storage === 'contact' ? group : null },
                source:   null,
                field:    null,
                rawPath:  null,
            }
        }

        if (ref === 'source') {
            const source = typeof obj.source === 'string' ? obj.source : null
            const field  = typeof obj.field === 'string' ? obj.field : null
            return { mode: 'source', variable: null, source, field, rawPath: null }
        }

        return emptyState()
    }

    if (typeof left === 'string') {
        const path = stripBraces(left)
        if (path === '') {
            return emptyState()
        }

        // Known source prefixes go straight to source mode.
        if (SOURCE_PREFIXES.some((prefix) => path.startsWith(prefix)) || path.startsWith('module.')) {
            const sf = pathToSourceField(path)
            if (sf) {
                return { mode: 'source', variable: null, source: sf.source, field: sf.field, rawPath: null }
            }
            return { mode: 'source', variable: null, source: null, field: null, rawPath: path }
        }

        // Try to map flow.* / contact.* into a known user variable.
        const matched = findVariableByPath(path, userVars)
        if (matched) {
            return { mode: 'user_variable', variable: matched, source: null, field: null, rawPath: null }
        }

        // contact.* without a matching user variable → source mode.
        if (path.startsWith('contact.')) {
            const sf = pathToSourceField(path)
            if (sf) {
                return { mode: 'source', variable: null, source: sf.source, field: sf.field, rawPath: null }
            }
        }

        // flow.* without a user-variable match → keep raw path for warning.
        return { mode: 'source', variable: null, source: null, field: null, rawPath: path }
    }

    return emptyState()
}
