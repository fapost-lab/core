<script setup lang="ts">
import type {FormField} from '@tma/dto/types'

interface Props {
    field: FormField
    value?: string
}

withDefaults(defineProps<Props>(), { value: '' })

const emit = defineEmits<{ 'update:value': [value: string] }>()

function onInput(e: Event) {
    emit('update:value', (e.target as HTMLInputElement).value)
}
</script>

<template>
    <input
        class="w-full rounded-xl border border-gray-200 px-4 py-3 text-base focus:border-blue-400 focus:outline-none"
        :placeholder="field.placeholder ?? ''"
        :value="value"
        @input="onInput"
    />
</template>
