<script setup>
import { computed } from 'vue'

const props = defineProps({
    config: { type: Object, default: () => ({}) },
})

const text = computed(() => {
    const raw = props.config.text
    if (!raw) return null
    const str = typeof raw === 'object' ? Object.values(raw)[0] ?? '' : String(raw)
    return str.length > 80 ? str.slice(0, 80) + '…' : str
})

const buttonCount = computed(() => {
    return Array.isArray(props.config.buttons) ? props.config.buttons.length : 0
})
</script>

<template>
    <div>
        <div v-if="text" class="summary-row">
            <span class="key">Text</span>
            <span class="val">{{ text }}</span>
        </div>
        <div v-if="buttonCount > 0" class="summary-row">
            <span class="key">Buttons</span>
            <span class="val muted">{{ buttonCount }} button{{ buttonCount !== 1 ? 's' : '' }}</span>
        </div>
    </div>
</template>

<style scoped>
.summary-row { display:flex; align-items:baseline; gap:6px; font-size:12.5px; margin-bottom:3px; }
.key  { color:var(--text-3); min-width:56px; }
.val  { color:var(--text); font-weight:500; }
.muted { color:var(--text-2); font-weight:400; }
</style>
