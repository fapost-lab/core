/**
 * A page or component of a Solution, named `<id>::<Path>` (`showcase::Board/Index`); Core's own names have no `::`
 * (ADR solution-console-ui).
 */
export interface SolutionName {
  /** The Solution's manifest `id`. */
  solution: string
  /** The page or component path inside the Solution's bundle map. */
  path: string
}

/** Same rule as Foundation's `ManifestSchema::ID_PATTERN`. */
const SOLUTION_ID = /^[a-z][a-z0-9_]{1,31}$/
const PATH = /^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/

/** Whether a string is a valid Solution id. */
export function isSolutionId(id: string): boolean {
  return SOLUTION_ID.test(id)
}

/** Whether a page name belongs to a Solution rather than to Core. */
export function isSolutionName(name: string): boolean {
  return name.includes('::')
}

/** Splits `<id>::<Path>`; `null` for a name that does not follow the rule. */
export function parseSolutionName(name: string): SolutionName | null {
  const parts = name.split('::')

  if (parts.length !== 2 || !SOLUTION_ID.test(parts[0]) || !PATH.test(parts[1])) {
    return null
  }

  return { solution: parts[0], path: parts[1] }
}
