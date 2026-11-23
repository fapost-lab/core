<script setup lang="ts">
/** A branch frame on the builder's graph: what one rule of a condition or one button of a message leads to. */
defineProps<{
    data: { title: string; width: number; height: number }
}>()
</script>

<template>
    <div class="graph-frame" :style="{ width: `${data.width}px`, height: `${data.height}px` }">
        <div class="graph-frame-title">{{ data.title }}</div>
    </div>
</template>

<style scoped>
.graph-frame {
    border: 1px dashed var(--border-2);
    border-radius: 12px;
    background: color-mix(in srgb, var(--surface-2) 45%, transparent);
}
.graph-frame-title {
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-3);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
