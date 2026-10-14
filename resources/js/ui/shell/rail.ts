import type {ShellApplication, ShellApplications} from './types'

/**
 * The applications the rail draws: the pinned ones in the user's order, then the current one when it is not pinned,
 * so the rail always shows where the user is.
 */
export function railApplications(data: ShellApplications): ShellApplication[] {
    const byKey = new Map(data.items.map((item) => [item.key, item]))
    const keys = [...data.rail]

    if (data.current !== null && !keys.includes(data.current)) {
        keys.push(data.current)
    }

    return keys.flatMap((key) => byKey.get(key) ?? [])
}

/** The applications whose name or caption contains the text, case-insensitively; all of them for an empty text. */
export function filterApplications(items: ShellApplication[], text: string): ShellApplication[] {
    const needle = text.trim().toLocaleLowerCase()

    if (needle === '') {
        return items
    }

    return items.filter((item) => `${item.label} ${item.short}`.toLocaleLowerCase().includes(needle))
}

/** The rail with the application pinned at its end, or taken out when it was pinned. */
export function togglePin(rail: string[], key: string): string[] {
    return rail.includes(key) ? rail.filter((candidate) => candidate !== key) : [...rail, key]
}

/** The rail with the application moved one place up (`-1`) or down (`1`); unchanged at either end. */
export function move(rail: string[], key: string, delta: -1 | 1): string[] {
    const from = rail.indexOf(key)
    const to = from + delta

    if (from === -1 || to < 0 || to >= rail.length) {
        return rail
    }

    const next = [...rail]
    next[from] = next[to]
    next[to] = key

    return next
}
