/**
 * Catalogue of operand sources available to the Branch (Condition) operand
 * picker when the user switches into "source mode".
 *
 * Built on top of `useFlowVariables.allVars` so we don't duplicate the
 * canonical list of platform fixtures. Modules are surfaced when at least
 * one variable from that source kind is registered (i.e. the module
 * exposes data through a registered DataAccessor).
 */

import {computed} from 'vue'
import type {PickerVariable} from '@builder/composables/useFlowVariables'
import {useFlowVariables} from '@builder/composables/useFlowVariables'

export interface ConditionSource {
    /** Stable id used in `rule.left.source` (e.g. 'rag', 'module.hr'). */
    id:     string
    /** Human label (e.g. 'RAG result', 'HR data'). */
    label:  string
    /** Visual icon emoji shown in the dropdown. */
    icon:   string
    /** Available field paths under this source. */
    fields: ConditionField[]
}

export interface ConditionField {
    /** Field path inside the source (e.g. 'found', 'last.status'). */
    id:    string
    /** Human label (defaults to the field id). */
    label: string
}

interface SourceSpec {
    id:    string
    label: string
    icon:  string
}

const STATIC_SOURCES: SourceSpec[] = [
    { id: 'contact', label: 'Contact field',      icon: '📇' },
    { id: 'rag',     label: 'RAG result',         icon: '🧠' },
    { id: 'call',    label: 'API response',       icon: '⚡' },
    { id: 'system',  label: 'Last user message',  icon: '💬' },
]

function fieldsFromVars(vars: PickerVariable[], stripFirstSegment = true): ConditionField[] {
    const seen = new Set<string>()
    const out: ConditionField[] = []
    for (const v of vars) {
        const segments = stripFirstSegment ? v.pathSegments.slice(1) : v.pathSegments
        const id       = segments.join('.')
        if (id === '' || seen.has(id)) {
            continue
        }
        seen.add(id)
        out.push({ id, label: v.label })
    }
    return out
}

export function useConditionSources() {
    const { allVars } = useFlowVariables()

    const sources = computed<ConditionSource[]>(() => {
        const result: ConditionSource[] = []

        // Static sources — driven by allVars filtered by source kind.
        for (const spec of STATIC_SOURCES) {
            let vars: PickerVariable[]
            switch (spec.id) {
                case 'contact':
                    vars = allVars.value.filter((v) => v.source.kind === 'contact-profile')
                    break
                case 'rag':
                    vars = allVars.value.filter((v) => v.source.kind === 'rag')
                    break
                case 'call':
                    vars = allVars.value.filter((v) => v.source.kind === 'api-response')
                    break
                case 'system':
                    vars = allVars.value.filter((v) => v.source.kind === 'last-user-message')
                    break
                default:
                    vars = []
            }

            const fields = fieldsFromVars(vars, true)
            if (fields.length === 0) {
                continue
            }
            result.push({ id: spec.id, label: spec.label, icon: spec.icon, fields })
        }

        // Dynamic module sources — group by module name.
        const moduleVars = allVars.value.filter((v) => v.source.kind === 'module')
        const byModule   = new Map<string, PickerVariable[]>()
        for (const v of moduleVars) {
            if (v.source.kind !== 'module') continue
            const arr = byModule.get(v.source.name) ?? []
            arr.push(v)
            byModule.set(v.source.name, arr)
        }
        for (const [name, vars] of byModule) {
            // module.<name>.<field...> → strip first two segments for field id.
            const fields: ConditionField[] = []
            const seen = new Set<string>()
            for (const v of vars) {
                const id = v.pathSegments.slice(2).join('.')
                if (id === '' || seen.has(id)) continue
                seen.add(id)
                fields.push({ id, label: v.label })
            }
            if (fields.length === 0) continue
            result.push({
                id:     `module.${name}`,
                label:  `${name.toUpperCase()} data`,
                icon:   '🏢',
                fields,
            })
        }

        return result
    })

    /** Lookup a source by id. */
    function findSource(id: string | null | undefined): ConditionSource | null {
        if (!id) return null
        return sources.value.find((s) => s.id === id) ?? null
    }

    return { sources, findSource }
}
