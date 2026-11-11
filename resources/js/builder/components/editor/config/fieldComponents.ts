import TextField from './fields/TextField.vue'
import TextareaField from './fields/TextareaField.vue'
import SelectField from './fields/SelectField.vue'
import ToggleField from './fields/ToggleField.vue'
import ArrayField from './fields/ArrayField.vue'
import JsonField from './fields/JsonField.vue'
import StatePickerField from './fields/StatePickerField.vue'
import KeyValueField from './fields/KeyValueField.vue'
import ObjectField from './fields/ObjectField.vue'
import ObjectArrayField from './fields/ObjectArrayField.vue'
import FlowPickerField from './fields/FlowPickerField.vue'
import EnumCardsField from './fields/EnumCardsField.vue'
import DurationField from './fields/DurationField.vue'

/** Schema field type → the component that edits it. Shared by SchemaFields and the template reference panel. */
export const FIELD_COMPONENTS: Record<string, object> = {
    string: TextField,
    text: TextareaField,
    number: TextField,
    boolean: ToggleField,
    enum: SelectField,
    array: ArrayField,
    json: JsonField,
    'state-picker': StatePickerField,
    'key-value': KeyValueField,
    object: ObjectField,
    'object-array': ObjectArrayField,
    'flow-picker': FlowPickerField,
    'enum-cards': EnumCardsField,
    duration: DurationField,
}

export function fieldComponentFor(type: string): object {
    return FIELD_COMPONENTS[type] ?? TextField
}
