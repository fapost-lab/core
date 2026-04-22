<script setup>
defineProps({
    field: { type: Object, required: true },
    value: { type: String, default: '' },
})

defineEmits(['update:value'])
</script>

<template>
    <input
        class="w-full rounded-xl border border-gray-200 px-4 py-3 text-base focus:border-blue-400 focus:outline-none"
        :placeholder="field.placeholder ?? ''"
        :value="value"
        @input="$emit('update:value', $event.target.value)"
    />
</template>
