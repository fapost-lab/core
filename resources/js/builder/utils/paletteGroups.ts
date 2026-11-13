/**
 * Palette grouping. Groups come from the server (`group` on each type, ordered `groups` list); a response without
 * them falls back to the raw `category` string in first-appearance order.
 */

import type {NodeGroup, NodeTypePayload} from '@builder/dto/types'

export interface PaletteGroup extends NodeGroup {
    types: NodeTypePayload[]
}

export function groupOf(type: NodeTypePayload): NodeGroup {
    if (type.group) {
        return type.group
    }

    const key = type.category ?? 'Core'

    return {key, label: key, owner: null}
}

export function groupPaletteTypes(types: NodeTypePayload[], order: NodeGroup[]): PaletteGroup[] {
    const byKey = new Map<string, PaletteGroup>()

    for (const type of types) {
        const group = groupOf(type)
        const existing = byKey.get(group.key)

        if (existing) {
            existing.types.push(type)
        } else {
            byKey.set(group.key, {...group, types: [type]})
        }
    }

    const position = new Map(order.map((g, index) => [g.key, index]))
    const arrival = new Map([...byKey.keys()].map((key, index) => [key, index]))

    return [...byKey.values()].sort((a, b) => {
        const pa = position.get(a.key) ?? Number.MAX_SAFE_INTEGER
        const pb = position.get(b.key) ?? Number.MAX_SAFE_INTEGER

        return pa !== pb ? pa - pb : (arrival.get(a.key)! - arrival.get(b.key)!)
    })
}
