import type {NavGroup, NavItem} from './types'

/** The path of a URL or path, without query string and fragment. */
export function pathOf(url: string): string {
    const end = url.search(/[?#]/)
    const path = end === -1 ? url : url.slice(0, end)

    try {
        return new URL(path, 'http://localhost').pathname
    } catch {
        return path
    }
}

function matches(item: NavItem, path: string): boolean {
    const target = pathOf(item.href).replace(/\/+$/, '')

    return path === target || path.startsWith(`${target}/`)
}

/**
 * The item the current page belongs to: the one with the longest matching path, so `/admin` (the dashboard) does not
 * claim `/admin/assistants`.
 */
export function findActive(groups: NavGroup[], currentUrl: string): NavItem | null {
    const path = pathOf(currentUrl).replace(/\/+$/, '')
    let best: NavItem | null = null
    let bestLength = -1

    for (const group of groups) {
        for (const item of group.items) {
            const length = pathOf(item.href).replace(/\/+$/, '').length

            if (matches(item, path) && length > bestLength) {
                best = item
                bestLength = length
            }
        }
    }

    return best
}

/** Breadcrumb labels: the root (assistant or administration), the group when it has a name, the current item. */
export function breadcrumbs(root: string, groups: NavGroup[], currentUrl: string): string[] {
    const active = findActive(groups, currentUrl)

    if (active === null) {
        return [root]
    }

    const group = groups.find((candidate) => candidate.items.includes(active))
    const crumbs = [root]

    if (group?.label && group.label !== active.label) {
        crumbs.push(group.label)
    }

    crumbs.push(active.label)

    return crumbs
}

/** Fills `:name` placeholders as Laravel's translator does; a value is inserted verbatim, `$&` included. */
export function interpolate(template: string, values: Record<string, string | number>): string {
    // The longest name goes first, so `:to` never eats the start of `:total`.
    return Object.entries(values)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((text, [name, value]) => text.replaceAll(`:${name}`, () => String(value)), template)
}

/** Up to two initials of a person's name, for the avatar. */
export function initials(name: string): string {
    const letters = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('')

    return letters === '' ? '?' : letters
}
