<script setup lang="ts">
/** One labelled number or word on a card; `null` shows that the figure is not available, never a made-up zero. */
defineProps<{
  label: string
  value: string | number | null
  missing: string
}>()
</script>

<template>
  <div class="bg-muted/50 flex flex-col gap-1 rounded-lg px-4 py-3" data-test="figure">
    <dt class="text-muted-foreground text-[13px]">{{ label }}</dt>
    <dd v-if="value !== null" class="font-display text-2xl leading-tight font-semibold tabular-nums"><slot>{{ value }}</slot></dd>
    <dd v-else class="text-muted-foreground text-sm" data-test="missing">{{ missing }}</dd>
  </div>
</template>
