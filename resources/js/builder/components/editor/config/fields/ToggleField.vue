<script setup lang="ts">
defineProps({
    value: { type: Boolean, default: false },
    schema: { type: Object, default: () => ({}) },
})

defineEmits(['update:value'])
</script>

<template>
    <label class="flex items-center gap-2 cursor-pointer">
        <input
            type="checkbox"
            class="rounded"
            :checked="value"
            @change="$emit('update:value', ($event.target as HTMLInputElement).checked)"
        >
        <span class="text-sm text-gray-600">{{ schema?.label ?? 'Enabled' }}</span>
    </label>
</template>
