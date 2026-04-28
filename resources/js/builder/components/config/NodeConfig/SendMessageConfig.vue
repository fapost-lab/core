<script setup lang="ts">
import SendMessageConfig from '../../editor/config/overrides/SendMessageConfig.vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <SendMessageConfig
        :node="{ id: 'config-preview', config: props.modelValue }"
        :schema="{}"
        @update:config="emit('update:modelValue', { ...props.modelValue, ...$event })"
    />
</template>
