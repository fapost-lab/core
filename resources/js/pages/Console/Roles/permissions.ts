import type { PermissionGroup } from './types'

/** How many of the group's permissions are among the selected ones. */
export function selectedInGroup(group: PermissionGroup, selected: readonly string[]): number {
  return group.permissions.filter((permission) => selected.includes(permission.value)).length
}

/**
 * The selection with every permission of the group added (`checked`) or removed, the rest left as it was. Backs the
 * group's "select all" / "clear all" toggle.
 */
export function toggleGroup(group: PermissionGroup, selected: readonly string[], checked: boolean): string[] {
  const values = group.permissions.map((permission) => permission.value)
  const rest = selected.filter((value) => !values.includes(value))

  return checked ? [...rest, ...values] : rest
}
