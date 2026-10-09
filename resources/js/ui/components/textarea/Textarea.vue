<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { useVModel } from "@vueuse/core"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes["class"]
  defaultValue?: string | number
  modelValue?: string | number
}>()

const emits = defineEmits<{
  (e: "update:modelValue", payload: string | number): void
}>()

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
})
</script>

<template>
  <textarea
    v-model="modelValue"
    data-slot="textarea"
    :class="cn('border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/25 aria-invalid:ring-invalid/15 dark:aria-invalid:ring-invalid/25 aria-invalid:border-invalid flex field-sizing-content min-h-16 w-full rounded-md border bg-transparent px-3 py-2.5 text-sm transition-[color,box-shadow] outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:border-border disabled:bg-surface-muted disabled:text-faint-foreground', props.class)"
  />
</template>
