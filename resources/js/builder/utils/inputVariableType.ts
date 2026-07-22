/**
 * Maps an input node's `expected_type` (what the user sends) to the variable
 * type persisted in save_to_variable / the schema registry.
 *
 * The variable type stays SEMANTIC (photo, contact, phone…) so pickers and
 * conditions know what the variable means — while the `label` clarifies the
 * actual stored shape (a "photo" stores a media reference object, not pixels).
 *
 * Mapping exists because not every expected_type is a valid VariableType:
 * image/document/video/voice/audio are transport subtypes that collapse into
 * the `photo` / `file` media-reference types.
 */
import type {VariableType} from '@builder/dto/types'

export interface StoredTypeInfo {
    /** Backend `VariableType` value persisted in save_to_variable / registry. */
    type: VariableType
    /** Human caption for the read-only Type row in the variable card. */
    label: string
}

const FILE: StoredTypeInfo = { type: 'file', label: 'File — media reference (id + metadata)' }

const MAP: Record<string, StoredTypeInfo> = {
    text:     { type: 'text',     label: 'Text' },
    number:   { type: 'number',   label: 'Number' },
    email:    { type: 'email',    label: 'Email' },
    phone:    { type: 'phone',    label: 'Phone' },
    date:     { type: 'date',     label: 'Date' },
    select:   { type: 'select',   label: 'Select — button value' },
    confirm:  { type: 'confirm',  label: 'Yes / No — boolean' },
    contact:  { type: 'contact',  label: 'Contact — object (phone, name)' },
    location: { type: 'location', label: 'Location — object (lat, lon)' },
    image:    { type: 'photo',    label: 'Photo — media reference (id + metadata)' },
    file:     FILE,
    document: FILE,
    video:    FILE,
    voice:    FILE,
    audio:    FILE,
}

const FALLBACK: StoredTypeInfo = { type: 'text', label: 'Text' }

/** Resolve the stored-type info for an input `expected_type`. */
export function storedTypeForInput(expectedType: string): StoredTypeInfo {
    return MAP[expectedType] ?? FALLBACK
}
