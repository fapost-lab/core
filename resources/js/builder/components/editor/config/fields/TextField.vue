<script setup lang="ts">
defineProps({
    value: { type: [String, Number], default: '' },
    schema: { type: Object, default: () => ({}) },
})

defineEmits(['update:value'])
</script>

<template>
    <input
        class="w-full rounded border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400"
        :type="schema?.type === 'number' ? 'number' : 'text'"
        :value="value ?? ''"
        :placeholder="schema?.placeholder ?? ''"
        @input="$emit('update:value', schema?.type === 'number'
            ? (($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value))
            : ($event.target as HTMLInputElement).value)"
    >
</template>
