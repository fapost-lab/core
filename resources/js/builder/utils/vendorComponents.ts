/**
 * Vendor (Solution) builder-override registry (ADR-06).
 *
 * Solutions publish Vue overrides into a predictable path under
 * `vendor/fapost/{solution}/resources/js/builder/`:
 *   - `{PascalCaseType}Config.vue`  → right-panel config override
 *   - `{PascalCaseType}Preview.vue` → node-card preview override
 *
 * They're picked up here through a static, eager `import.meta.glob` — no
 * dynamic runtime import (CSP / isolation, ADR-06). With no vendor packages
 * installed the glob resolves to `{}` and both maps are empty; the builder
 * then falls back to Core overrides or the generic schema renderer.
 *
 * Requires `npm run build` after installing a Solution (Task 25 /
 * `platform:update`) for new files to be bundled.
 */
import {vendorComponentType} from './vendorComponentName'

type VueModule = { default: object }

const configModules = import.meta.glob<VueModule>(
    '../../../../vendor/fapost/*/resources/js/builder/*Config.vue',
    {eager: true},
)

const previewModules = import.meta.glob<VueModule>(
    '../../../../vendor/fapost/*/resources/js/builder/*Preview.vue',
    {eager: true},
)

function buildMap(
    modules: Record<string, VueModule>,
    suffix: 'Config' | 'Preview',
): Record<string, object> {
    const out: Record<string, object> = {}
    for (const [path, mod] of Object.entries(modules)) {
        const type = vendorComponentType(path, suffix)
        if (type !== null) {
            out[type] = mod.default
        }
    }
    return out
}

/** node type (`snake_case`) → vendor config-panel component. */
export const vendorConfigs: Record<string, object> = buildMap(configModules, 'Config')

/** node type (`snake_case`) → vendor node-card preview component. */
export const vendorPreviews: Record<string, object> = buildMap(previewModules, 'Preview')
