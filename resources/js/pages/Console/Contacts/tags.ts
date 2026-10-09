/**
 * Adds what was typed to a contact's tags. A comma ends a tag, so "a, b" is two; blanks and tags already there are
 * dropped (the server normalises the set again, this only keeps the chips honest).
 */
export function addTags(current: string[], input: string): string[] {
  const result = [...current]

  for (const part of input.split(',')) {
    const tag = part.trim()

    if (tag !== '' && !result.includes(tag)) {
      result.push(tag)
    }
  }

  return result
}

export function removeTag(current: string[], tag: string): string[] {
  return current.filter((existing) => existing !== tag)
}
