<script setup>
defineProps({
    trigger: { type: Object, default: null },
})

const emit = defineEmits(['select'])
</script>

<template>
    <div class="trigger-card" @click="emit('select')">
        <div class="trigger-icon">⚡</div>
        <div v-if="trigger">
            <div class="trigger-label">Trigger: {{ trigger.label ?? trigger.type }}</div>
            <div class="trigger-sub">{{ trigger.subLabel ?? trigger.type }}</div>
        </div>
        <div v-else>
            <div class="trigger-label">No trigger configured</div>
            <div class="trigger-sub">Click to set up a trigger</div>
        </div>
    </div>
</template>

<style scoped>
.trigger-card {
    width: 100%;
    max-width: 480px;
    background: var(--surface);
    border: 1.5px dashed var(--border-2);
    border-radius: var(--radius-lg);
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-2);
    font-size: 12.5px;
    cursor: pointer;
    transition: border-color .15s;
}
.trigger-card:hover { border-color: var(--primary); }
.trigger-icon {
    width: 28px;
    height: 28px;
    background: var(--sky-bg);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sky);
    font-size: 13px;
    flex-shrink: 0;
}
.trigger-label { font-weight: 500; color: var(--text); font-size: 12.5px; }
.trigger-sub   { font-size: 11.5px; color: var(--text-3); }
</style>
