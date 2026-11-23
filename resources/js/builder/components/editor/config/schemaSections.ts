import type {FieldEntry} from './SchemaFields.vue'

/** One accordion of a schema-driven form: the fields a declared section names, or every field when none is declared. */
export interface SchemaSection {
    key:       string
    label:     string
    icon:      string | null
    collapsed: boolean
    fields:    FieldEntry[]
}

interface SectionSchema {
    key?:       unknown
    label?:     unknown
    icon?:      unknown
    collapsed?: unknown
    fields?:    unknown
}

// Top-level `required: [...]` enumerates required field keys instead of
// describing a field itself. `sections: [...]` declares grouping. Both
// are reserved keys — filter when scanning for field entries.
const RESERVED_KEYS = new Set(['required', 'sections'])

function isFieldSchema(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && 'type' in (value as Record<string, unknown>)
}

/** The fields a `config_schema` declares, in order, each marked required by itself or by the top-level list. */
export function schemaFields(schema: Record<string, unknown> | null | undefined): FieldEntry[] {
    const raw = schema ?? {}
    const requiredList = Array.isArray(raw.required) ? (raw.required as string[]) : []

    const entries: FieldEntry[] = []
    for (const [key, value] of Object.entries(raw)) {
        if (RESERVED_KEYS.has(key) || !isFieldSchema(value)) continue

        entries.push({
            key,
            schema: value,
            required: Boolean(value.required) || requiredList.includes(key),
        })
    }

    return entries
}

/**
 * The sections a `config_schema` declares, each with the fields it names; a key the schema does not declare is
 * skipped (backend validators catch the authoring mistake) and an empty section is dropped. Without declared
 * sections, one section — `fallback` — holds every field.
 */
export function schemaSections(
    schema: Record<string, unknown> | null | undefined,
    fallback: {key: string, label: string},
): SchemaSection[] {
    const raw = schema ?? {}
    const fields = schemaFields(raw)
    const declared = Array.isArray(raw.sections) ? (raw.sections as SectionSchema[]) : null

    if (declared === null) {
        return [{key: fallback.key, label: fallback.label, icon: null, collapsed: false, fields}]
    }

    const byKey = new Map(fields.map((field) => [field.key, field]))

    return declared.map((section, index) => {
        const declaredFields = Array.isArray(section.fields) ? (section.fields as unknown[]) : []

        return {
            key:       typeof section.key === 'string' ? section.key : `section-${index}`,
            label:     typeof section.label === 'string' ? section.label : 'Section',
            icon:      typeof section.icon === 'string' && section.icon !== '' ? section.icon : null,
            collapsed: Boolean(section.collapsed),
            fields:    declaredFields
                .filter((fieldKey): fieldKey is string => typeof fieldKey === 'string')
                .map((fieldKey) => byKey.get(fieldKey))
                .filter((entry): entry is FieldEntry => entry !== undefined),
        }
    }).filter((section) => section.fields.length > 0)
}
