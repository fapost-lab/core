/**
 * Adds what was typed to a list of values. A comma ends a value, so "a, b" is two; blanks and values already there
 * are dropped, and a different case is a different value. The list given is not changed.
 */
export function addValues(current: string[], input: string): string[] {
  const result = [...current]

  for (const part of input.split(",")) {
    const value = part.trim()

    if (value !== "" && !result.includes(value)) {
      result.push(value)
    }
  }

  return result
}

export function removeValue(current: string[], value: string): string[] {
  return current.filter((existing) => existing !== value)
}
