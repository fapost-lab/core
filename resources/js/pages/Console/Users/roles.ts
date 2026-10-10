/**
 * The list with `value` added (once) or removed, in the order the list already had. Shared by the user's role
 * checkboxes and the role's permission checklists.
 */
export function toggleValue(list: readonly string[], value: string, checked: boolean): string[] {
  if (checked) {
    return list.includes(value) ? [...list] : [...list, value]
  }

  return list.filter((item) => item !== value)
}
