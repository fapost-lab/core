<script setup lang="ts">
import type {FormField} from '@tma/dto/types'

interface Props {
    field: FormField
    value?: boolean
}

withDefaults(defineProps<Props>(), { value: false })

const emit = defineEmits<{ 'update:value': [value: boolean] }>()

function onChange(e: Event) {
    emit('update:value', (e.target as HTMLInputElement).checked)
}
</script>

<template>
    <label class="flex cursor-pointer items-center gap-3">
        <input
            type="checkbox"
            class="h-5 w-5 rounded"
            :checked="value"
            @change="onChange"
        />
        <span class="text-sm text-gray-600">{{ field.label }}</span>
    </label>
</template>
