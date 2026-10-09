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
      <AlertDialogHeader>
        <AlertDialogTitle>{{ title }}</AlertDialogTitle>
        <AlertDialogDescription>{{ description }}</AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>{{ cancelLabel }}</AlertDialogCancel>
        <AlertDialogAction :class="cn(destructive && buttonVariants({ variant: 'destructive' }))" @click="emit('confirm')">
          {{ confirmLabel }}
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
