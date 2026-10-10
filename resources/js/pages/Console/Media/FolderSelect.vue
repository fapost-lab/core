<script setup lang="ts">
import { computed } from 'vue'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { folderIdOf, folderLabel, ROOT } from './folders'
import type { FolderNode } from './types'

/**
 * Picks a folder of the tree, or the root. The model is the folder's id, `null` for the root.
 */
const props = defineProps<{
  id: string
  folders: readonly FolderNode[]
  rootLabel: string
  invalid?: boolean
  describedBy?: string
}>()

const model = defineModel<string | null>({ default: null })

const value = computed({
  get: () => model.value ?? ROOT,
  set: (next: unknown) => {
    model.value = folderIdOf(next)
  },
})
</script>

<template>
  <Select v-model="value">
    <SelectTrigger :id="props.id" class="w-full" :aria-invalid="props.invalid" :aria-describedby="props.describedBy">
      <SelectValue />
    </SelectTrigger>
    <SelectContent>
      <SelectItem :value="ROOT">{{ props.rootLabel }}</SelectItem>
      <SelectItem v-for="folder in props.folders" :key="folder.id" :value="folder.id">{{ folderLabel(folder) }}</SelectItem>
    </SelectContent>
  </Select>
</template>
