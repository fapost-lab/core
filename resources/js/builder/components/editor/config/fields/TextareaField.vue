<script setup lang="ts">
defineProps({
    value: { type: String, default: '' },
    schema: { type: Object, default: () => ({}) },
})

defineEmits(['update:value'])
</script>

<template>
    <textarea
        class="w-full rounded border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400 resize-none"
        rows="3"
        :value="value ?? ''"
        :placeholder="schema?.placeholder ?? ''"
        @input="$emit('update:value', ($event.target as HTMLTextAreaElement).value)"
    />
</template>
