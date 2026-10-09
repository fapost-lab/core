<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { Label } from "@fapost/ui/components/label"
import { cn } from "@fapost/ui/lib/utils"

/**
 * A form control with its label and its server-side error. The control goes in the slot and takes the same `id`;
 * give it `:aria-invalid="!!error"` and `:aria-describedby` from the slot props so the error is read with it.
 */
const props = defineProps<{
  /** The id of the control in the slot. */
  id: string
  label: string
  error?: string
  hint?: string
  class?: HTMLAttributes["class"]
}>()

const errorId = computed(() => `${props.id}-error`)
const hintId = computed(() => `${props.id}-hint`)
const describedBy = computed(() => [props.error ? errorId.value : null, props.hint ? hintId.value : null].filter(Boolean).join(" ") || undefined)
</script>

<template>
  <div :class="cn('grid gap-2', props.class)">
    <Label :for="id">{{ label }}</Label>
    <slot :id="id" :invalid="!!error" :described-by="describedBy" />
    <p v-if="hint" :id="hintId" class="text-muted-foreground text-sm">{{ hint }}</p>
    <p v-if="error" :id="errorId" role="alert" class="text-destructive text-sm">{{ error }}</p>
  </div>
</template>
