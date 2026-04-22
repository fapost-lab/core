<script setup>
defineProps({
    field: { type: Object, required: true },
    value: { type: Boolean, default: false },
})

defineEmits(['update:value'])
</script>

<template>
    <label class="flex cursor-pointer items-center gap-3">
        <input
            type="checkbox"
            class="h-5 w-5 rounded"
            :checked="value"
            @change="$emit('update:value', $event.target.checked)"
        />
        <span class="text-sm text-gray-600">{{ field.label }}</span>
    </label>
</template>
