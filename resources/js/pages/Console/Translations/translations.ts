import type { TranslationCell, TranslationRow, TranslationStatus } from './types'

/** The edit form's starting values: the scope's own overrides, by language. */
export function formValues(row: TranslationRow): Record<string, string> {
  return Object.fromEntries(row.languages.map((cell) => [cell.language, cell.override]))
}

/** The text a cell falls back to when its override is cleared: the workspace's override, else the catalog default. */
export function fallbackOf(cell: TranslationCell): string {
  return cell.inherited ?? cell.default
}

/** The badge of a cell that is not the catalog default, by meaning; none for a default. */
export function statusVariant(status: TranslationStatus): 'warning' | 'info' | null {
  return status === 'override' ? 'warning' : status === 'inherited' ? 'info' : null
}

/** The cell of a language, for the column of that language. */
export function cellOf(row: TranslationRow, language: string): TranslationCell | undefined {
  return row.languages.find((cell) => cell.language === language)
}

export function truncate(text: string, length = 60): string {
  return text.length > length ? `${text.slice(0, length).trimEnd()}…` : text
}
