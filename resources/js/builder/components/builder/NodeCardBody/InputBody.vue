<script setup>
defineProps({
    config: { type: Object, default: () => ({}) },
})
</script>

<template>
    <div>
        <div class="summary-row">
            <span class="key">Var</span>
            <span v-if="config.save_to" class="val mono">{{ config.save_to }}</span>
            <span v-else class="val muted">not saved</span>
        </div>
    </div>
</template>

<style scoped>
.summary-row { display:flex; align-items:baseline; gap:6px; font-size:12.5px; margin-bottom:3px; }
.key  { color:var(--text-3); min-width:56px; }
.val  { color:var(--text); font-weight:500; }
.mono { font-family:'DM Mono',monospace; font-size:12px; }
.muted { color:var(--text-2); font-weight:400; }
</style>
