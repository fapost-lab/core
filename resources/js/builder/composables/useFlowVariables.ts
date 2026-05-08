/**
 * Collects variables available for the VariablePicker — built-in platform
 * variables plus user-defined ones extracted from current flow nodes.
 *
 * Reads both new-shape configs (`config.variable`, `config.save_to_variable`,
 * `config.operations[*].variable`) and legacy `save_to` / `target+key` so the
 * picker keeps working through the storage rework migration.
 */

import {computed} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {decompileLegacySaveTo} from '@builder/utils/variableCompiler'
import type {FlowNode, Variable} from '@builder/dto/types'

export type PickerSourceKind =
    | 'contact-profile'
    | 'temporary'
    | 'rag'
    | 'api-response'
    | 'last-user-message'
    | 'module'

export type PickerSource =
    | { kind: 'contact-profile' }
    | { kind: 'temporary' }
    | { kind: 'rag' }
    | { kind: 'api-response' }
    | { kind: 'last-user-message' }
    | { kind: 'module', name: string }

export interface PickerVariable {
    /** Snippet inserted into text fields, e.g. '{{contact.foo}}'. */
    snippet:      string
    /** Path inside the snippet without braces, e.g. 'contact.foo'. */
    path:         string
    /** Hierarchy of segments — used for prefix grouping. */
    pathSegments: string[]
    /** Display label (last segment by default). */
    label:        string
    source:       PickerSource
    /** Auto-derived group name (subsection inside Contact). null when flat. */
    group:        string | null
    /** Whether this came from user-defined node, vs built-in platform fixture. */
    isCustom:     boolean
    /** Optional source-node label for tooltip ("from <node label>"). */
    sourceNode?:  string
}

// ── Built-in platform fixtures ───────────────────────────────────────────────

const BUILTIN_CONTACT: Array<{ name: string; label: string }> = [
    { name: 'id',       label: 'ID' },
    { name: 'language', label: 'Language' },
    { name: 'name',     label: 'Name' },
    { name: 'username', label: 'Username' },
    { name: 'phone',    label: 'Phone' },
    { name: 'channel',  label: 'Channel' },
]

const BUILTIN_RAG: Array<{ name: string; label: string }> = [
    { name: 'answer',     label: 'Answer' },
    { name: 'found',      label: 'Found' },
    { name: 'confidence', label: 'Confidence' },
    { name: 'intent',     label: 'Intent' },
]

const BUILTIN_LAST_USER: Array<{ path: string; label: string }> = [
    { path: 'system.last_user_message', label: 'Last user message' },
    { path: 'system.language',          label: 'System language' },
    { path: 'system.retry_count',       label: 'Retry count' },
]

const BUILTIN_API: Array<{ path: string; label: string }> = [
    { path: 'call.last.status',  label: 'Last call status' },
    { path: 'call.last.body',    label: 'Last call body' },
    { path: 'call.last.headers', label: 'Last call headers' },
]

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeVar(
    path: string,
    label: string,
    source: PickerSource,
    opts: { isCustom?: boolean; sourceNode?: string; group?: string | null } = {},
): PickerVariable {
    const segments = path.split('.').filter((s) => s !== '')
    return {
        snippet:      `{{${path}}}`,
        path,
        pathSegments: segments,
        label,
        source,
        group:        opts.group ?? null,
        isCustom:     opts.isCustom ?? false,
        ...(opts.sourceNode ? { sourceNode: opts.sourceNode } : {}),
    }
}

/** Build picker entry for a Variable descriptor (storage rework shape). */
function fromVariable(v: Variable, sourceNode: string): PickerVariable | null {
    const name = (v.name ?? '').trim()
    if (name === '') {
        return null
    }
    if (v.storage === 'contact') {
        const group = v.group && v.group.trim() !== '' ? v.group.trim() : null
        const path  = group ? `contact.${group}.${name}` : `contact.${name}`
        const label = group ? `${group} · ${name}` : name
        return makeVar(path, label, { kind: 'contact-profile' }, {
            isCustom: true,
            sourceNode,
            group,
        })
    }
    // session
    return makeVar(`flow.${name}`, name, { kind: 'temporary' }, {
        isCustom: true,
        sourceNode,
    })
}

/**
 * Convert a legacy save_to-style string ("flow.x", "contact.g.x", "x") into a
 * PickerVariable. Routes contact.* → contact-profile, everything else → temp.
 */
function fromLegacyPath(saveTo: string, sourceNode: string): PickerVariable | null {
    const decoded = decompileLegacySaveTo(saveTo)
    if (decoded.name === '') {
        return null
    }
    return fromVariable(decoded, sourceNode)
}

// ── Composable ───────────────────────────────────────────────────────────────

export function useFlowVariables() {
    const builderStore = useBuilderStore()

    /**
     * Variables collected from current flow nodes (Input / Assign /
     * SendMessage save_to_variable / set_attribute legacy).
     */
    const userVars = computed<PickerVariable[]>(() => {
        const nodes = (builderStore.definition?.nodes ?? []) as FlowNode[]
        const seen  = new Map<string, PickerVariable>()
        const push  = (pv: PickerVariable | null) => {
            if (pv && !seen.has(pv.path)) {
                seen.set(pv.path, pv)
            }
        }

        for (const node of nodes) {
            const cfg       = (node.config ?? {}) as Record<string, unknown>
            const nodeLabel = node.label ?? node.type

            // Input — new shape
            if (node.type === 'input') {
                const v = cfg.variable as Record<string, unknown> | undefined
                if (v && typeof v === 'object' && typeof v.name === 'string') {
                    const storage = v.storage === 'session' ? 'session' : 'contact'
                    const group   = typeof v.group === 'string' && v.group !== '' ? v.group : null
                    push(fromVariable(
                        {
                            name:    v.name,
                            type:    (typeof v.type === 'string' ? v.type : 'text') as Variable['type'],
                            storage,
                            group:   storage === 'contact' ? group : null,
                        },
                        nodeLabel,
                    ))
                    continue
                }
                // legacy
                if (typeof cfg.save_to === 'string') {
                    push(fromLegacyPath(cfg.save_to, nodeLabel))
                }
                continue
            }

            // SendMessage — save_to_variable (new) or save_to (legacy)
            if (node.type === 'send_message') {
                const v = cfg.save_to_variable as Record<string, unknown> | undefined
                if (v && typeof v === 'object' && typeof v.name === 'string') {
                    const storage = v.storage === 'session' ? 'session' : 'contact'
                    const group   = typeof v.group === 'string' && v.group !== '' ? v.group : null
                    push(fromVariable(
                        {
                            name:    v.name,
                            type:    (typeof v.type === 'string' ? v.type : 'text') as Variable['type'],
                            storage,
                            group:   storage === 'contact' ? group : null,
                        },
                        nodeLabel,
                    ))
                    continue
                }
                if (typeof cfg.save_to === 'string') {
                    push(fromLegacyPath(cfg.save_to, nodeLabel))
                }
                continue
            }

            // Assign — operations[*].variable (new) | target+key (legacy) | flat key
            if (node.type === 'assign') {
                const ops = cfg.operations
                if (Array.isArray(ops)) {
                    for (const opRaw of ops) {
                        const op = opRaw as Record<string, unknown>
                        const v  = op.variable as Record<string, unknown> | undefined
                        if (v && typeof v === 'object' && typeof v.name === 'string') {
                            const storage = v.storage === 'session' ? 'session' : 'contact'
                            const group   = typeof v.group === 'string' && v.group !== '' ? v.group : null
                            push(fromVariable(
                                {
                                    name:    v.name,
                                    type:    (typeof v.type === 'string' ? v.type : 'text') as Variable['type'],
                                    storage,
                                    group:   storage === 'contact' ? group : null,
                                },
                                nodeLabel,
                            ))
                            continue
                        }
                        // legacy: { target: 'flow'|'contact', key: 'x' [, group: 'g'] }
                        const target = typeof op.target === 'string' ? op.target : null
                        const key    = typeof op.key === 'string' ? op.key : null
                        if (target && key) {
                            const group = typeof op.group === 'string' && op.group !== '' ? op.group : null
                            const path  = target === 'contact'
                                ? (group ? `contact.${group}.${key}` : `contact.${key}`)
                                : `flow.${key}`
                            push(fromLegacyPath(path, nodeLabel))
                        }
                    }
                    continue
                }
                // very-legacy single-op
                if (typeof cfg.key === 'string') {
                    push(fromLegacyPath(`flow.${cfg.key}`, nodeLabel))
                }
                continue
            }

            // Legacy set_attribute — kept for backwards compat
            if (node.type === 'set_attribute' && typeof cfg.key === 'string') {
                push(fromLegacyPath(`flow.${cfg.key}`, nodeLabel))
            }
        }

        return [...seen.values()]
    })

    /** All variables — built-in + user-defined, deduped by path. */
    const allVars = computed<PickerVariable[]>(() => {
        const seen = new Map<string, PickerVariable>()
        const push = (pv: PickerVariable) => {
            if (!seen.has(pv.path)) {
                seen.set(pv.path, pv)
            }
        }

        // Built-in contact profile
        for (const c of BUILTIN_CONTACT) {
            push(makeVar(`contact.${c.name}`, c.label, { kind: 'contact-profile' }))
        }
        // Built-in last-user-message + system
        for (const s of BUILTIN_LAST_USER) {
            push(makeVar(s.path, s.label, { kind: 'last-user-message' }))
        }
        // Built-in RAG
        for (const r of BUILTIN_RAG) {
            push(makeVar(`rag.${r.name}`, r.label, { kind: 'rag' }))
        }
        // Built-in API response
        for (const a of BUILTIN_API) {
            push(makeVar(a.path, a.label, { kind: 'api-response' }))
        }
        // User-defined (overrides built-ins on path collisions are avoided
        // because Map.set is gated by has()).
        for (const v of userVars.value) {
            push(v)
        }

        return [...seen.values()]
    })

    return { allVars, userVars }
}
