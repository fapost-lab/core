<script setup>
import { computed } from 'vue'

const props = defineProps({
    config: { type: Object, default: () => ({}) },
})

const waitLabel = computed(() => {
    const s = Number(props.config.seconds ?? 60)
    if (s >= 3600) return `${Math.round(s / 3600)} h`
    if (s >= 60)   return `${Math.round(s / 60)} min`
    return `${s}s`
})
</script>

<template>
    <div class="summary-row">
        <span class="key">Wait</span>
        <span class="val">{{ waitLabel }}</span>
    </div>
</template>

<style scoped>
.summary-row { display:flex; align-items:baseline; gap:6px; font-size:12.5px; }
.key  { color:var(--text-3); min-width:56px; }
.val  { color:var(--text); font-weight:500; }
</style>
