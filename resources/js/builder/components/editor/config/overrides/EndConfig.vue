<script setup lang="ts">
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'

const STATUS_OPTIONS = [
    {
        value: 'success',
        label: 'Success',
        icon:  '✓',
        hint:  'Flow finished as expected',
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        icon:  '⊘',
        hint:  'User cancelled or session timed out',
    },
    {
        value: 'failed',
        label: 'Failed',
        icon:  '✕',
        hint:  'Flow ended due to error',
    },
] as const

type EndStatus = typeof STATUS_OPTIONS[number]['value']

const props = defineProps({
    node: { type: Object as () => Record<string, unknown>, required: true },
})

const emit = defineEmits(['update:config'])

const status = computed<EndStatus>(() => {
    const cfg = (props.node.config ?? {}) as Record<string, unknown>
    const raw = typeof cfg.status === 'string' ? cfg.status : 'success'
    return (STATUS_OPTIONS.find((o) => o.value === raw)?.value ?? 'success') as EndStatus
})

function setStatus(next: EndStatus) {
    if (next === status.value) {
        return
    }
    const cfg = { ...(props.node.config ?? {}) as Record<string, unknown>, status: next }
    emit('update:config', cfg)
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Completion" default-open>
            <div class="end-status-list">
                <label
                    v-for="opt in STATUS_OPTIONS"
                    :key="opt.value"
                    class="end-status-option"
                    :class="[
                        `end-status-option--${opt.value}`,
                        { 'end-status-option--active': status === opt.value },
                    ]"
                >
                    <input
                        type="radio"
                        :value="opt.value"
                        :checked="status === opt.value"
                        @change="setStatus(opt.value)"
                    >
                    <span class="end-status-icon">{{ opt.icon }}</span>
                    <span class="end-status-text">
                        <span class="end-status-label">{{ opt.label }}</span>
                        <span class="end-status-hint">{{ opt.hint }}</span>
                    </span>
                </label>
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.end-status-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.end-status-option {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    background: var(--surface);
    transition: border-color .15s, background .15s;
}
.end-status-option:hover {
    border-color: var(--border-2);
}
.end-status-option input {
    /* The whole row is the clickable target; the native radio is just a
       semantic anchor for keyboard / a11y, not a visual element. */
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.end-status-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    background: var(--surface-2);
    color: var(--text-3);
    flex-shrink: 0;
}
.end-status-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.end-status-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}
.end-status-hint {
    font-size: 11.5px;
    color: var(--text-3);
}

/* Selected state — per-status colour accents. */
.end-status-option--active.end-status-option--success {
    border-color: var(--sage, #5a6e58);
    background: color-mix(in srgb, var(--sage, #5a6e58) 8%, var(--surface));
}
.end-status-option--active.end-status-option--success .end-status-icon {
    background: var(--sage-bg, #e6ede4);
    color: var(--sage, #5a6e58);
}

.end-status-option--active.end-status-option--cancelled {
    border-color: var(--amber, #b07c2c);
    background: color-mix(in srgb, var(--amber, #b07c2c) 8%, var(--surface));
}
.end-status-option--active.end-status-option--cancelled .end-status-icon {
    background: var(--amber-bg, #f6ecd6);
    color: var(--amber, #b07c2c);
}

.end-status-option--active.end-status-option--failed {
    border-color: var(--rose, #b94a4a);
    background: color-mix(in srgb, var(--rose, #b94a4a) 8%, var(--surface));
}
.end-status-option--active.end-status-option--failed .end-status-icon {
    background: var(--rose-bg, #f3dada);
    color: var(--rose, #b94a4a);
}
</style>
