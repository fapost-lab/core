<script setup>
defineProps({
    warnings: { type: Array, default: () => [] },
})

const emit = defineEmits(['dismiss'])
</script>

<template>
    <div v-if="warnings.length > 0" class="mpw-root">
        <svg class="mpw-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <div class="mpw-messages">
            <p v-for="(warning, i) in warnings" :key="i" class="mpw-line">{{ warning }}</p>
        </div>
        <button type="button" class="mpw-close" @click="emit('dismiss')">×</button>
    </div>
</template>

<style scoped>
.mpw-root {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 12px;
    background: color-mix(in srgb, var(--amber, #d97706) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--amber, #d97706) 35%, transparent);
    border-radius: 7px;
    font-size: 12px;
    color: var(--text);
}

.mpw-icon {
    width: 14px;
    height: 14px;
    color: var(--amber, #d97706);
    flex-shrink: 0;
    margin-top: 1px;
}

.mpw-messages { flex: 1; }
.mpw-line { margin: 0; line-height: 1.4; }
.mpw-line + .mpw-line { margin-top: 2px; }

.mpw-close {
    background: transparent;
    border: none;
    font-size: 16px;
    line-height: 1;
    color: var(--text-3);
    cursor: pointer;
    padding: 0;
    flex-shrink: 0;
}
.mpw-close:hover { color: var(--text); }
</style>
