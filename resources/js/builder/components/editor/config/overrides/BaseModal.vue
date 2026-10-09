<script setup lang="ts">
import {useTemplateRef, watch} from 'vue'
import {onClickOutside, useEventListener} from '@vueuse/core'

const props = defineProps({
    open:  { type: Boolean, required: true },
    title: { type: String,  default: '' },
    width: { type: String,  default: '480px' },
})

const emit = defineEmits(['close'])

const dialogRef = useTemplateRef('dialogRef')

onClickOutside(dialogRef, () => {
    if (props.open) emit('close')
})

useEventListener(document, 'keydown', (e) => {
    if (e.key === 'Escape' && props.open) emit('close')
})

watch(() => props.open, (val) => {
    document.body.style.overflow = val ? 'hidden' : ''
})
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="bm-overlay">
            <div ref="dialogRef" class="bm-dialog" :style="{ width }">
                <header v-if="title" class="bm-header">
                    <span class="bm-title">{{ title }}</span>
                    <button type="button" class="bm-close" @click="emit('close')">×</button>
                </header>
                <div class="bm-body">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="bm-footer">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.bm-overlay {
    position: fixed;
    inset: 0;
    background: color-mix(in srgb, var(--scrim) 45%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(2px);
}

.bm-dialog {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-modal);
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 32px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    font-family: var(--font-sans);
}

.bm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}
.bm-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.bm-close {
    width: 24px;
    height: 24px;
    border: none;
    background: transparent;
    color: var(--text-3);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    transition: color .12s, background .12s;
}
.bm-close:hover { color: var(--text); background: var(--surface-2); }

.bm-body {
    padding: 16px;
    overflow-y: auto;
    overflow-x: hidden;
    flex: 1;
}

.bm-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    background: var(--surface-2);
}
</style>
