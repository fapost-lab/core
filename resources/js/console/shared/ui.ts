/**
 * The public surface of `@fapost/ui` a prebuilt Solution bundle imports at run time.
 *
 * Built as its own Vite entry with its full export signature (`preserveEntrySignatures: 'strict'` in
 * `vite.config.ts`), so the components Core's console does not use stay here and every other module is shared with
 * the console's chunks: one instance of each component, one provide/inject tree. The console's import map points
 * `@fapost/ui` and every subpath in `ui-surface.json` at this one entry (`App\Http\Shell\SolutionImportMap`).
 *
 * One line per subpath in `ui-surface.json`, in the same order; `ui-surface.test.ts` holds both to each other and
 * keeps export names unique across subpaths, since `export *` silently drops a name two subpaths share. `shell` and
 * the live-updates helpers are internal to the console and stay out (ADR solution-console-ui).
 */
export * from '@fapost/ui/components/alert-dialog'
export * from '@fapost/ui/components/avatar'
export * from '@fapost/ui/components/badge'
export * from '@fapost/ui/components/button'
export * from '@fapost/ui/components/card'
export * from '@fapost/ui/components/checkbox'
export * from '@fapost/ui/components/command'
export * from '@fapost/ui/components/confirm-dialog'
export * from '@fapost/ui/components/data-table'
export * from '@fapost/ui/components/dialog'
export * from '@fapost/ui/components/dropdown-menu'
export * from '@fapost/ui/components/empty-state'
export * from '@fapost/ui/components/form-field'
export * from '@fapost/ui/components/form-section'
export * from '@fapost/ui/components/input'
export * from '@fapost/ui/components/label'
export * from '@fapost/ui/components/localized-field'
export * from '@fapost/ui/components/options-picker'
export * from '@fapost/ui/components/select'
export * from '@fapost/ui/components/separator'
export * from '@fapost/ui/components/sheet'
export * from '@fapost/ui/components/skeleton'
export * from '@fapost/ui/components/sonner'
export * from '@fapost/ui/components/switch'
export * from '@fapost/ui/components/table'
export * from '@fapost/ui/components/tabs'
export * from '@fapost/ui/components/textarea'
export * from '@fapost/ui/components/tooltip'
export * from '@fapost/ui/components/value-list-input'
export * from '@fapost/ui/lib/utils'
export * from '@fapost/ui/lib/relative-time'
