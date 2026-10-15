import {type ClassValue, clsx} from 'clsx'
import {twMerge} from 'tailwind-merge'

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs))
}

/** Fills `:name` placeholders as Laravel's translator does; a value is inserted verbatim, `$&` included. */
export function interpolate(template: string, values: Record<string, string | number>): string {
    // The longest name goes first, so `:to` never eats the start of `:total`.
    return Object.entries(values)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((text, [name, value]) => text.replaceAll(`:${name}`, () => String(value)), template)
}
