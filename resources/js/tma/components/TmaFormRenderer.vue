<script setup>
import TmaTextField from './fields/TmaTextField.vue'
import TmaSelectField from './fields/TmaSelectField.vue'
import TmaCheckboxField from './fields/TmaCheckboxField.vue'

const FIELD_COMPONENTS = {
    text: TmaTextField,
    select: TmaSelectField,
    checkbox: TmaCheckboxField,
}

const props = defineProps({
    fields: { type: Array, required: true },
    answers: { type: Object, required: true },
})

const emit = defineEmits(['update:answers'])

function update(fieldId, value) {
    emit('update:answers', { ...props.answers, [fieldId]: value })
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-for="field in fields" :key="field.id" class="flex flex-col gap-1.5">
            <label class="text-sm font-medium text-gray-700">
                {{ field.label }}
                <span v-if="field.required" class="ml-0.5 text-red-400">*</span>
            </label>
            <component
                :is="FIELD_COMPONENTS[field.type] ?? TmaTextField"
                :field="field"
                :value="answers[field.id]"
                @update:value="update(field.id, $event)"
            />
        </div>
    </div>
</template>
