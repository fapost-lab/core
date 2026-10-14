import { isSolutionId, parseSolutionName } from './names'

/** One Solution's prebuilt bundle as the console's root view lists it in `#fapost-solutions` (design §5.3). */
export interface SolutionBundle {
  pages: Record<string, string>
  components: Record<string, string>
  css: string[]
}

/** The bundles of the Solutions active for the tenant, by Solution id. */
export type SolutionBundleMap = Record<string, SolutionBundle>

/** The module that holds a named page or component and the stylesheets to attach before mounting it. */
export interface SolutionModuleRef {
  url: string
  css: string[]
}

export type SolutionModuleKind = 'pages' | 'components'

function isRecordOfStrings(value: unknown): value is Record<string, string> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) && Object.values(value).every((item) => typeof item === 'string')
}

function asBundle(value: unknown): SolutionBundle | null {
  if (typeof value !== 'object' || value === null) {
    return null
  }

  const { pages = {}, components = {}, css = [] } = value as Record<string, unknown>

  if (!isRecordOfStrings(pages) || !isRecordOfStrings(components) || !Array.isArray(css) || !css.every((item) => typeof item === 'string')) {
    return null
  }

  return { pages, components, css }
}

/**
 * Reads the map the server rendered. Anything malformed counts as no Solutions: a broken map must cost the Solution
 * pages, not the console.
 */
export function parseSolutionBundleMap(json: string | null | undefined): SolutionBundleMap {
  if (!json) {
    return {}
  }

  let parsed: unknown

  try {
    parsed = JSON.parse(json)
  } catch {
    return {}
  }

  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return {}
  }

  const map: SolutionBundleMap = {}

  for (const [id, value] of Object.entries(parsed)) {
    const bundle = asBundle(value)

    if (isSolutionId(id) && bundle !== null) {
      map[id] = bundle
    }
  }

  return map
}

/** Where a `<id>::<Path>` page or component lives; `null` when the name is malformed or not in the map. */
export function findSolutionModule(map: SolutionBundleMap, name: string, kind: SolutionModuleKind): SolutionModuleRef | null {
  const parsed = parseSolutionName(name)

  if (parsed === null || !Object.hasOwn(map, parsed.solution)) {
    return null
  }

  const bundle = map[parsed.solution]

  if (!Object.hasOwn(bundle[kind], parsed.path)) {
    return null
  }

  return { url: bundle[kind][parsed.path], css: bundle.css }
}
