import { relativeTime } from '@fapost/ui/lib/relative-time'

/**
 * The cell types a table column can declare without its own component (Foundation's `CellType`), and how a value
 * reads in each. Kept beside the kit's DataTable rather than in its index: the public surface grows only once
 * `first-solution` has exercised it.
 */
export type CellType = 'text' | 'badge' | 'number' | 'date' | 'status'

/** The tones of the kit's soft badges and status dots (Foundation's `Tone`). */
export type CellTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

export type CellValue = string | number | boolean | null | undefined

/** What a cell draws: nothing (a dash), plain text, a soft badge or a status dot. */
export interface FormattedCell {
  kind: 'empty' | 'text' | 'badge' | 'status'
  text: string
  tone: CellTone
  /** The raw value on hover, where the text is a rendering of it (a relative date). */
  title?: string
  /** A number reads right-aligned in tabular figures. */
  numeric: boolean
}

const TONES: readonly CellTone[] = ['neutral', 'success', 'warning', 'danger', 'info']

function toneOf(value: string, tones: Partial<Record<string, CellTone>>): CellTone {
  const tone = tones[value]

  return tone !== undefined && TONES.includes(tone) ? tone : 'neutral'
}

const plain = (text: string): FormattedCell => ({ kind: 'text', text, tone: 'neutral', numeric: false })

/**
 * Formats one value for its cell type. A missing value is empty whatever the type; a value the type cannot read (a
 * word in a number column, a date that does not parse) is shown as it came rather than hidden. An unknown type reads
 * as text, so a newer Foundation never breaks an older console.
 */
export function formatCell(
  type: string,
  value: CellValue,
  options: { locale: string; tones?: Partial<Record<string, CellTone>>; now?: Date },
): FormattedCell {
  if (value === null || value === undefined || value === '') {
    return { kind: 'empty', text: '', tone: 'neutral', numeric: false }
  }

  const text = String(value)

  switch (type) {
    case 'number': {
      const number = typeof value === 'number' ? value : Number(text)

      return Number.isFinite(number) && typeof value !== 'boolean'
        ? { kind: 'text', text: new Intl.NumberFormat(options.locale).format(number), tone: 'neutral', numeric: true }
        : plain(text)
    }
    case 'date':
      return typeof value === 'string' && !Number.isNaN(Date.parse(value))
        ? { kind: 'text', text: relativeTime(value, options.locale, options.now), tone: 'neutral', title: value, numeric: false }
        : plain(text)
    case 'badge':
      return { kind: 'badge', text, tone: toneOf(text, options.tones ?? {}), numeric: false }
    case 'status':
      return { kind: 'status', text, tone: toneOf(text, options.tones ?? {}), numeric: false }
    default:
      return plain(text)
  }
}
