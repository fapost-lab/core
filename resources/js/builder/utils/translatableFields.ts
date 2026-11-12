/**
 * A top-level config field whose text is translated per language: the Content tab edits it, and a template parameter
 * on it holds a language map. Button labels are nested, not top-level, so they are not described here.
 */
export interface TranslatableFieldDescriptor {
    nodeType: string
    field: string
    /** Builder translation key of the entry's label in the Content tab. */
    labelKey: string
    /** Badge of the entry: `text` has none. */
    kind: 'text' | 'caption' | 'prompt' | 'invalid'
    /** Whether the Content tab lists the field for a node with this config. */
    shown: (config: Record<string, unknown>) => boolean
}

function isFilled(value: unknown): boolean {
    return value !== undefined && value !== null && value !== ''
}

/** In the order the Content tab lists them for one node. */
export const TRANSLATABLE_FIELDS: TranslatableFieldDescriptor[] = [
    {
        nodeType: 'send_message', field: 'text', labelKey: 'content.send_message_text', kind: 'text',
        shown: (config) => ['text', 'text_with_keyboard'].includes(String(config.content_type ?? 'text')),
    },
    {
        nodeType: 'send_message', field: 'caption', labelKey: 'content.send_message_caption', kind: 'caption',
        shown: (config) => ['image', 'document', 'video'].includes(String(config.content_type ?? 'text')),
    },
    {nodeType: 'input', field: 'prompt', labelKey: 'content.input_prompt', kind: 'prompt', shown: (config) => isFilled(config.prompt)},
    {
        nodeType: 'input', field: 'on_invalid_message', labelKey: 'content.input_on_invalid', kind: 'invalid',
        shown: (config) => isFilled(config.on_invalid_message),
    },
]

/** The translatable fields of a node type, in listing order. */
export function translatableFieldsOf(nodeType: string): TranslatableFieldDescriptor[] {
    return TRANSLATABLE_FIELDS.filter((descriptor) => descriptor.nodeType === nodeType)
}

export function isTranslatableField(nodeType: string, field: string): boolean {
    return TRANSLATABLE_FIELDS.some((descriptor) => descriptor.nodeType === nodeType && descriptor.field === field)
}
