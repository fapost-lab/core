<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { interpolate } from '@fapost/ui/shell'
import FolderSelect from './FolderSelect.vue'
import { moveTargets } from './folders'
import type { FolderNode, MediaPageProps } from './types'

/**
 * Deletes a folder. What it holds (files, those in the trash too, and subfolders) first moves to the folder chosen
 * here, its own subtree excluded, or to the root. The list stays on its open folder, unless that one is gone: then the
 * server opens the folder that received the contents.
 */
const props = defineProps<{
  folder: FolderNode | null
  tree: readonly FolderNode[]
  /** The folder the list has open, so the server can stay on it. */
  openFolderId: string | null
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)

const form = useForm<{ move_to: string | null; open_folder: string | null }>({ move_to: null, open_folder: null })
const targets = computed(() => (props.folder ? moveTargets(props.tree, props.folder) : []))
const hasContents = computed(() => (props.folder?.files ?? 0) + (props.folder?.folders ?? 0) > 0)

watch(open, (isOpen) => {
  if (isOpen) {
    form.clearErrors()
    form.move_to = props.folder?.parentId ?? null
    form.open_folder = props.openFolderId
  }
})

function submit(): void {
  if (props.folder) {
    form.delete(props.folder.destroyUrl, { preserveScroll: true, onSuccess: () => (open.value = false) })
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <TriangleAlert class="text-destructive size-5" aria-hidden="true" />
          {{ interpolate(t.delete_folder.title, { name: folder?.name ?? '' }) }}
        </DialogTitle>
        <DialogDescription>
          {{ hasContents ? interpolate(t.delete_folder.has_contents, { files: folder?.files ?? 0, folders: folder?.folders ?? 0 }) : t.delete_folder.empty }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField v-if="hasContents" id="media-folder-move-to" :label="t.delete_folder.move_to" :error="form.errors.move_to" v-slot="{ invalid, describedBy }">
          <FolderSelect id="media-folder-move-to" v-model="form.move_to" :folders="targets" :root-label="t.root" :invalid="invalid" :described-by="describedBy" />
        </FormField>
        <p v-else-if="form.errors.move_to" role="alert" class="text-destructive text-[12.5px]">{{ form.errors.move_to }}</p>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" variant="destructive" :disabled="form.processing">{{ t.delete_folder.confirm }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
