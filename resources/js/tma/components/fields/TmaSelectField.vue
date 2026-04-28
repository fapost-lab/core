<script setup lang="ts">
import type {FormField} from '@tma/dto/types'

interface Props {
    field: FormField
    value?: string
}

withDefaults(defineProps<Props>(), { value: '' })

const emit = defineEmits<{ 'update:value': [value: string] }>()

function onChange(e: Event) {
    emit('update:value', (e.target as HTMLSelectElement).value)
}
</script>

<template>
    <select
        class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-base focus:border-blue-400 focus:outline-none"
        :value="value"
        @change="onChange"
    >
        <option value="" disabled>Select...</option>
        <option v-for="opt in field.options ?? []" :key="opt" :value="opt">{{ opt }}</option>
    </select>
</template>
