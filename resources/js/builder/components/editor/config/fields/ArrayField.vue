<script setup lang="ts">
const props = defineProps({
    value: { type: Array, default: () => [] },
    schema: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

function add() {
    emit('update:value', [...props.value, ''])
}

function update(index: number, nextValue: string) {
    const updated = [...props.value]
    updated[index] = nextValue
    emit('update:value', updated)
}

function remove(index: number) {
    emit('update:value', props.value.filter((_: unknown, i: number) => i !== index))
}
</script>

<template>
    <div class="array-field">
        <div
            v-for="(item, index) in value"
            :key="index"
            class="array-row"
        >
            <input
                class="field-input"
                :value="item"
                :placeholder="(schema as { placeholder?: string })?.placeholder ?? ''"
                @input="update(index, ($event.target as HTMLInputElement).value)"
            >
            <button
                type="button"
                class="array-del"
                title="Remove"
                @click="remove(index)"
            >×</button>
        </div>
        <button
            type="button"
            class="add-item-btn"
            @click="add"
        >+ Add item</button>
    </div>
</template>

<style scoped>
.array-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.array-row {
    display: flex;
    align-items: center;
    gap: 6px;
}
.array-row > input {
    flex: 1;
    min-width: 0;
}
.array-del {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border: none;
    background: transparent;
    color: var(--text-3);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    transition: color .12s, background .12s;
}
.array-del:hover {
    color: var(--rose);
    background: var(--rose-bg);
}
</style>
