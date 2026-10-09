<script setup lang="ts">
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@fapost/ui/components/alert-dialog"
import { TriangleAlert } from "@lucide/vue"
import { buttonVariants } from "@fapost/ui/components/button"
import { cn } from "@fapost/ui/lib/utils"

/**
 * Asks before something that cannot be undone. Bind it with `v-model:open`; `confirm` fires when the user agrees, and
 * the dialog closes itself either way. `destructive` (the default) paints the action as a danger.
 */
withDefaults(
  defineProps<{
    title: string
    description: string
    confirmLabel: string
    cancelLabel: string
    destructive?: boolean
  }>(),
  { destructive: true },
)

const open = defineModel<boolean>("open", { default: false })

const emit = defineEmits<{
  confirm: []
}>()
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogContent>
      <div class="flex gap-3.5">
        <span
          v-if="destructive"
          class="bg-danger text-danger-foreground flex size-10 shrink-0 items-center justify-center rounded-full"
        >
          <TriangleAlert class="size-5" aria-hidden="true" />
        </span>
        <AlertDialogHeader class="gap-1.5">
          <AlertDialogTitle>{{ title }}</AlertDialogTitle>
          <AlertDialogDescription>{{ description }}</AlertDialogDescription>
        </AlertDialogHeader>
      </div>
      <AlertDialogFooter>
        <AlertDialogCancel>{{ cancelLabel }}</AlertDialogCancel>
        <AlertDialogAction :class="cn(destructive && buttonVariants({ variant: 'destructive' }))" @click="emit('confirm')">
          {{ confirmLabel }}
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
