/**
 * Pure helpers for VariablePicker grouping. Take a flat PickerVariable list
 * and split it into source sections, then split contact-profile section by
 * group prefix so the UI can render `survey_q1` subsections without UI logic.
 */

import type {PickerSourceKind, PickerVariable} from '@builder/composables/useFlowVariables'

export interface ContactSplit {
    rootVars:    PickerVariable[]
    groupedVars: Record<string, PickerVariable[]>
}

/** Order in which source sections are rendered in the picker. */
export const SOURCE_ORDER: PickerSourceKind[] = [
    'contact-profile',
    'temporary',
    'last-user-message',
    'rag',
    'api-response',
    'module',
]

/** Group flat variables by source kind, preserving insertion order. */
export function groupVariablesBySource(
    vars: PickerVariable[],
): Record<PickerSourceKind, PickerVariable[]> {
    const out: Record<PickerSourceKind, PickerVariable[]> = {
        'contact-profile':   [],
        'temporary':         [],
        'rag':               [],
        'api-response':      [],
        'last-user-message': [],
        'module':            [],
    }
    for (const v of vars) {
        out[v.source.kind].push(v)
    }
    return out
}

/**
 * Split contact-profile variables by their group (`pathSegments[1]` when
 * pathSegments.length >= 3). A group is materialized as a separate subsection
 * if it has ≥2 variables OR ≥1 user-defined variable (isCustom=true).
 *
 * Variables that don't qualify for a subsection fall back into rootVars.
 */
export function splitContactByGroup(contactVars: PickerVariable[]): ContactSplit {
    const byGroup = new Map<string, PickerVariable[]>()
    const ungrouped: PickerVariable[] = []

    for (const v of contactVars) {
        if (v.group) {
            const arr = byGroup.get(v.group) ?? []
            arr.push(v)
            byGroup.set(v.group, arr)
        } else {
            ungrouped.push(v)
        }
    }

    const groupedVars: Record<string, PickerVariable[]> = {}
    const rootVars: PickerVariable[] = [...ungrouped]

    for (const [name, list] of byGroup) {
        const hasCustom = list.some((v) => v.isCustom)
        if (list.length >= 2 || hasCustom) {
            groupedVars[name] = list
        } else {
            // Single built-in inside a group → render flat.
            rootVars.push(...list)
        }
    }

    return { rootVars, groupedVars }
}
