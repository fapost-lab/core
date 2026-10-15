/** One choice a picker offers: the stored value and the text shown for it. */
export interface PickerOption {
  value: string
  label: string
}

/** A chosen value as a chip; `missing` when the offered list no longer has it (a deleted group). */
export interface PickerChip {
  value: string
  label: string
  missing: boolean
}

/** Options given as plain strings are their own labels. */
export function normalizeOptions(options: ReadonlyArray<string | PickerOption>): PickerOption[] {
  return options.map((option) => (typeof option === "string" ? { value: option, label: option } : option))
}

/**
 * The options whose label contains the query, case-insensitively, or whose value is the query (a country code typed
 * in full). A blank query keeps them all.
 */
export function filterOptions(options: PickerOption[], query: string): PickerOption[] {
  const needle = query.trim().toLowerCase()

  if (needle === "") {
    return options
  }

  return options.filter((option) => option.label.toLowerCase().includes(needle) || option.value.toLowerCase() === needle)
}

/** The chosen values in the order they were chosen; one the list lacks shows `missingLabel`, or its raw value. */
export function chipsOf(selected: string[], options: PickerOption[], missingLabel?: string): PickerChip[] {
  const labels = new Map(options.map((option) => [option.value, option.label]))

  return selected.map((value) => {
    const label = labels.get(value)

    return { value, label: label ?? missingLabel ?? value, missing: label === undefined }
  })
}

/** Ticks or unticks a value; a ticked value goes last and never twice. */
export function toggleValue(selected: string[], value: string, on: boolean): string[] {
  const without = selected.filter((existing) => existing !== value)

  return on ? [...without, value] : without
}
