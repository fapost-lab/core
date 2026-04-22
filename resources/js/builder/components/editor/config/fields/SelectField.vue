<script setup>
defineProps({
    value: { type: String, default: '' },
    schema: { type: Object, required: true },
})

defineEmits(['update:value'])
</script>

<template>
    <select
        class="w-full rounded border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400"
        :value="value ?? ''"
        @change="$emit('update:value', $event.target.value)"
    >
        <option
            v-for="opt in (schema.values ?? [])"
            :key="opt"
            :value="opt"
        >
            {{ opt }}
        </option>
    </select>
</template>
