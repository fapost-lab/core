<script setup lang="ts">
/**
 * Loop end has no user-editable config — it is created, wired and removed by
 * the builder together with its parent loop. This panel just explains that.
 */
defineProps({
    node:   {type: Object as () => Record<string, unknown>, required: true},
    schema: {type: Object as () => Record<string, unknown>, required: true},
})
</script>

<template>
    <div class="loop-end-config">
        <p>
            This is the end of a loop body. It returns execution to its loop node
            to re-check the condition.
        </p>
        <p class="lec-muted">
            It is managed automatically — added with the loop and removed when the
            loop is deleted. There is nothing to configure here.
        </p>
    </div>
</template>

<style scoped>
.loop-end-config {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--text);
}
.lec-muted {
    color: var(--text-3);
    font-size: 12px;
}
</style>
