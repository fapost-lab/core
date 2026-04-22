<script setup>
defineProps({
    afterNodeId: { type: String, default: null },
})

const emit = defineEmits(['insert'])
</script>

<template>
    <div class="insert-point" @click="emit('insert', afterNodeId)">
        <div class="insert-line"></div>
        <button class="insert-btn" type="button">+ Add block</button>
        <div class="insert-line"></div>
    </div>
</template>

<style scoped>
.insert-point {
    width: 100%;
    max-width: 480px;
    display: flex;
    align-items: center;
    gap: 8px;
    opacity: 0;
    transition: opacity .15s;
    cursor: pointer;
    padding: 2px 0;
}
.insert-point:hover { opacity: 1; }
.insert-line { flex: 1; height: 1px; background: var(--border-2); }
.insert-btn {
    padding: 3px 10px;
    border-radius: 5px;
    font-size: 11.5px;
    font-weight: 500;
    color: var(--text-3);
    border: 1px dashed var(--border-2);
    background: var(--surface);
    cursor: pointer;
    white-space: nowrap;
    transition: all .15s;
    font-family: 'DM Sans', sans-serif;
}
.insert-point:hover .insert-btn {
    color: var(--primary);
    border-color: var(--primary);
    background: var(--primary-bg);
}
</style>
