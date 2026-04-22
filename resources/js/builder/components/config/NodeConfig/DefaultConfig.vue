<script setup>
import ConfigField from '../ConfigField.vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    schema:     { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

function onFieldUpdate(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<template>
    <template v-if="Object.keys(schema).length > 0">
        <ConfigField
            v-for="(fieldSchema, key) in schema"
            :key="key"
            :field-key="key"
            :schema="fieldSchema"
            :model-value="modelValue[key]"
            @update:model-value="onFieldUpdate(key, $event)"
        />
    </template>
    <p v-else class="no-schema">No configuration fields for this node type.</p>
</template>

<style scoped>
.no-schema { font-size: 12px; color: var(--text-3); }
</style>
