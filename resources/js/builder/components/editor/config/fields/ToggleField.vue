<script setup lang="ts">
defineProps({
    value: { type: Boolean, default: false },
    schema: { type: Object, default: () => ({}) },
})

defineEmits(['update:value'])
</script>

<template>
    <label class="toggle-row">
        <input
            type="checkbox"
            class="toggle-check"
            :checked="value"
            @change="$emit('update:value', ($event.target as HTMLInputElement).checked)"
        >
        <span class="toggle-label">{{ value ? 'Enabled' : 'Disabled' }}</span>
    </label>
</template>

<style scoped>
.toggle-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.toggle-check {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    cursor: pointer;
    accent-color: var(--primary);
}
.toggle-label {
    font-size: 12.5px;
    color: var(--text-2);
}
</style>
