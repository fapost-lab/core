<script setup lang="ts">
/**
 * Radio-card enum picker. A richer alternative to the plain `SelectField`
 * dropdown: each option is a full-width clickable card with an optional
 * icon, hint line and per-option colour accent.
 *
 * Schema shape:
 *   { type: 'enum-cards', options: [{ value, label, icon?, hint?, accent? }] }
 *
 * `accent` is one of the known keys below (`sage` / `amber` / `rose` …);
 * unknown accents fall back to the neutral selected state.
 */
import {computed} from 'vue'

interface EnumCardOption {
    value:   string
    label:   string
    icon?:   string
    hint?:   string
    accent?: string
}

interface EnumCardsSchema {
    options?: EnumCardOption[]
    default?: string
}

const props = defineProps({
    value:  { type: [String, null] as unknown as () => string | null, default: '' },
    schema: { type: Object as () => EnumCardsSchema, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

const options = computed<EnumCardOption[]>(() =>
    Array.isArray(props.schema?.options) ? props.schema.options : [],
)

const current = computed<string>(() => {
    const raw = props.value ?? ''
    if (options.value.some((o) => o.value === raw)) {
        return raw
    }
    return (props.schema?.default as string | undefined) ?? options.value[0]?.value ?? ''
})

function pick(next: string) {
    if (next !== current.value) {
        emit('update:value', next)
    }
}
</script>

<template>
    <div class="enum-cards">
        <label
            v-for="opt in options"
            :key="opt.value"
            class="enum-cards-option"
            :class="[
                opt.accent ? `enum-cards-option--accent-${opt.accent}` : null,
                { 'enum-cards-option--active': current === opt.value },
            ]"
        >
            <input
                type="radio"
                :value="opt.value"
                :checked="current === opt.value"
                @change="pick(opt.value)"
            >
            <span v-if="opt.icon" class="enum-cards-icon">{{ opt.icon }}</span>
            <span class="enum-cards-text">
                <span class="enum-cards-label">{{ opt.label }}</span>
                <span v-if="opt.hint" class="enum-cards-hint">{{ opt.hint }}</span>
            </span>
        </label>
    </div>
</template>

<style scoped>
.enum-cards {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.enum-cards-option {
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
.enum-cards-option:hover {
    border-color: var(--border-2);
}
.enum-cards-option input {
    /* The whole row is the clickable target; the native radio is just a
       semantic anchor for keyboard / a11y, not a visual element. */
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.enum-cards-icon {
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
.enum-cards-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.enum-cards-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}
.enum-cards-hint {
    font-size: 11.5px;
    color: var(--text-3);
}

/* Neutral selected state (no accent / unknown accent). */
.enum-cards-option--active {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, var(--surface));
}

/* Per-accent selected states. */
.enum-cards-option--active.enum-cards-option--accent-sage {
    border-color: var(--sage);
    background: color-mix(in srgb, var(--sage) 8%, var(--surface));
}
.enum-cards-option--active.enum-cards-option--accent-sage .enum-cards-icon {
    background: var(--sage-bg);
    color: var(--sage);
}

.enum-cards-option--active.enum-cards-option--accent-amber {
    border-color: var(--amber);
    background: color-mix(in srgb, var(--amber) 8%, var(--surface));
}
.enum-cards-option--active.enum-cards-option--accent-amber .enum-cards-icon {
    background: var(--amber-bg);
    color: var(--amber);
}

.enum-cards-option--active.enum-cards-option--accent-rose {
    border-color: var(--rose);
    background: color-mix(in srgb, var(--rose) 8%, var(--surface));
}
.enum-cards-option--active.enum-cards-option--accent-rose .enum-cards-icon {
    background: var(--rose-bg);
    color: var(--rose);
}
</style>
