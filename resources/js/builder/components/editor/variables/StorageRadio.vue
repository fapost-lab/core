<script setup lang="ts">
import type {VariableStorage} from '@builder/dto/types'

defineProps<{
    modelValue: VariableStorage
}>()

defineEmits<{
    (e: 'update:modelValue', value: VariableStorage): void
}>()
</script>

<template>
    <div class="storage-radio" role="radiogroup">
        <button
            type="button"
            class="storage-option"
            :class="{ active: modelValue === 'contact' }"
            role="radio"
            :aria-checked="modelValue === 'contact'"
            title="Contact profile — persists on the contact"
            @click="$emit('update:modelValue', 'contact')"
        >
            <span class="icon">💾</span>
            <span class="label">Profile</span>
        </button>
        <button
            type="button"
            class="storage-option"
            :class="{ active: modelValue === 'session' }"
            role="radio"
            :aria-checked="modelValue === 'session'"
            title="Temporary — only for the current flow session"
            @click="$emit('update:modelValue', 'session')"
        >
            <span class="icon">⏱</span>
            <span class="label">Temporary</span>
        </button>
    </div>
</template>

<style scoped>
.storage-radio {
    display: inline-flex;
    padding: 2px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-2);
    gap: 2px;
}
.storage-option {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border: none;
    border-radius: calc(var(--radius) - 2px);
    background: transparent;
    color: var(--text-2);
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
    transition: background 120ms, color 120ms;
}
.storage-option:hover:not(.active) {
    color: var(--text);
}
.storage-option.active {
    background: var(--surface);
    color: var(--text);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.storage-option .icon {
    font-size: 12px;
    line-height: 1;
}
.storage-option .label {
    user-select: none;
}
</style>
