/**
 * Top-level config fields whose text is translated per language (the Content
 * tab edits them). Button labels are nested, not top-level, so they are not here.
 */
const TRANSLATABLE_FIELDS: Record<string, string[]> = {
    send_message: ['text', 'caption'],
    input: ['prompt', 'on_invalid_message'],
}

export function isTranslatableField(nodeType: string, field: string): boolean {
    return TRANSLATABLE_FIELDS[nodeType]?.includes(field) ?? false
}
