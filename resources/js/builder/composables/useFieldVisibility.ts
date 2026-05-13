/**
 * Resolve a field's `visible_when` condition against the root config.
 *
 * The short form `{ 'transport_options.success_when': 'custom' }` is
 * sugar for an `equals` check at that dot-path. The verbose form is an
 * array of `{ field, op, value }` clauses, all combined with logical AND
 * (no `or` in V1 — flag for follow-up if it ever comes up).
 *
 * Returns `true` when the field should render (no condition, or every
 * clause passes), `false` when at least one clause fails.
 */
export interface VisibleWhenClause {
  field: string
  op?: 'equals' | 'in' | 'truthy'
  value?: unknown
}

export function resolveVisibility(
  fieldSchema: Record<string, unknown> | null | undefined,
  rootConfig: Record<string, unknown>,
): boolean {
  const condition = fieldSchema?.visible_when
  if (!condition) return true

  const clauses: VisibleWhenClause[] = Array.isArray(condition)
    ? (condition as VisibleWhenClause[])
    : Object.entries(condition as Record<string, unknown>).map(([field, value]) => ({
      field,
      op: 'equals',
      value,
    }))

  for (const clause of clauses) {
    if (!evaluateClause(clause, rootConfig)) {
      return false
    }
  }

  return true
}

function evaluateClause(clause: VisibleWhenClause, root: Record<string, unknown>): boolean {
  const actual = readPath(root, clause.field)
  const op = clause.op ?? 'equals'

  if (op === 'truthy') return Boolean(actual)
  if (op === 'in') return Array.isArray(clause.value) && clause.value.includes(actual)
  return actual === clause.value
}

function readPath(obj: Record<string, unknown>, path: string): unknown {
  if (!path) return undefined
  const parts = path.split('.')
  let cursor: unknown = obj
  for (const part of parts) {
    if (cursor === null || cursor === undefined || typeof cursor !== 'object') {
      return undefined
    }
    cursor = (cursor as Record<string, unknown>)[part]
  }
  return cursor
}
