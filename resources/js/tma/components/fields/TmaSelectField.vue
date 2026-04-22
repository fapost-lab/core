<script setup>
defineProps({
    field: { type: Object, required: true },
    value: { type: String, default: '' },
})

defineEmits(['update:value'])
</script>

<template>
    <select
        class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-base focus:border-blue-400 focus:outline-none"
        :value="value"
        @change="$emit('update:value', $event.target.value)"
    >
        <option value="" disabled>Select...</option>
        <option v-for="opt in field.options ?? []" :key="opt" :value="opt">{{ opt }}</option>
    </select>
</template>
