<script setup>
import { computed } from 'vue'
import TextField from './fields/TextField.vue'
import TextareaField from './fields/TextareaField.vue'
import SelectField from './fields/SelectField.vue'
import ToggleField from './fields/ToggleField.vue'
import ArrayField from './fields/ArrayField.vue'

const props = defineProps({
    node: { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

const FIELD_COMPONENTS = {
    string: TextField,
    text: TextareaField,
    number: TextField,
    boolean: ToggleField,
    enum: SelectField,
    array: ArrayField,
}

const schemaEntries = computed(() => Object.entries(props.schema ?? {}))

function update(key, value) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <template
            v-for="[key, fieldSchema] in schemaEntries"
            :key="key"
        >
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-gray-500">
                    {{ fieldSchema.label ?? key }}
                    <span
                        v-if="fieldSchema.required"
                        class="text-red-400 ml-0.5"
                    >*</span>
                </label>
                <component
                    :is="FIELD_COMPONENTS[fieldSchema.type] ?? TextField"
                    :value="node.config?.[key]"
                    :schema="fieldSchema"
                    @update:value="update(key, $event)"
                />
            </div>
        </template>
    </div>
</template>
