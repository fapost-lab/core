<script setup lang="ts">
import type {FormAnswers, FormField} from '@tma/dto/types'
import TmaTextField from './fields/TmaTextField.vue'
import TmaSelectField from './fields/TmaSelectField.vue'
import TmaCheckboxField from './fields/TmaCheckboxField.vue'

const FIELD_COMPONENTS = {
    text: TmaTextField,
    select: TmaSelectField,
    checkbox: TmaCheckboxField,
}

interface Props {
    fields: FormField[]
    answers: FormAnswers
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:answers': [value: FormAnswers]
}>()

function update(fieldId: string, value: string | boolean) {
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
