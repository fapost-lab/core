<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue'

/** A prominent notice of a wizard step (what cannot be undone), in the shell's warning colours. */
defineProps<{ title: string; text: string }>()
</script>

<template>
  <div role="note" class="bg-warning/15 border-warning/40 flex gap-3 rounded-lg border p-4">
    <TriangleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    <div>
      <p class="text-sm font-semibold">{{ title }}</p>
      <p class="text-muted-foreground mt-1 text-sm">{{ text }}</p>
    </div>
  </div>
</template>
