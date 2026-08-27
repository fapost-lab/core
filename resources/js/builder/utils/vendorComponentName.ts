/**
 * Pure filename → node-type mapping for vendor (Solution) builder overrides.
 *
 * Naming convention (ADR-06): `{PascalCaseType}Config.vue` and
 * `{PascalCaseType}Preview.vue` resolve to a `snake_case` node type —
 * `SyncEmployeeConfig.vue` → `sync_employee`. Kept free of `import.meta.glob`
 * so it stays trivially unit-testable without a Vite/Vue runtime.
 */

/** Convert a PascalCase identifier to snake_case (`SyncEmployee` → `sync_employee`). */
export function pascalToSnake(value: string): string {
    return value.replace(/[A-Z]/g, (char, offset: number) =>
        offset === 0 ? char.toLowerCase() : `_${char.toLowerCase()}`,
    )
}

/**
 * Derive the node type a vendor component targets from its file path.
 *
 * Returns null when the basename doesn't end in the expected `{suffix}.vue`
 * marker, or when stripping the suffix leaves an empty name — so a stray file
 * never maps onto a real node type.
 */
export function vendorComponentType(filePath: string, suffix: 'Config' | 'Preview'): string | null {
    const base = filePath.split('/').pop() ?? ''
    const name = base.replace(/\.vue$/, '')

    if (!name.endsWith(suffix)) {
        return null
    }

    const pascal = name.slice(0, name.length - suffix.length)
    if (pascal === '') {
        return null
    }

    return pascalToSnake(pascal)
}
