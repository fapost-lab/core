<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import FolderSelect from './FolderSelect.vue'
import type { FolderNode, MediaPageProps } from './types'

/**
 * Creates a folder (inside the open one by default) or renames one. A depth over the limit comes back as an error on
 * the parent.
 */
const props = defineProps<{
  tree: readonly FolderNode[]
  /** The folder to rename; none creates one. */
  folder: FolderNode | null
  storeUrl: string
  parentId: string | null
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)

const form = useForm<{ name: string; parent_id: string | null }>({ name: '', parent_id: null })

watch(open, (isOpen) => {
  if (isOpen) {
    form.clearErrors()
    form.name = props.folder?.name ?? ''
    form.parent_id = props.folder ? props.folder.parentId : props.parentId
  }
})

function submit(): void {
  const options = { preserveScroll: true, onSuccess: () => (open.value = false) }

  if (props.folder) {
    form.put(props.folder.updateUrl, options)
  } else {
    form.post(props.storeUrl, options)
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ folder ? t.folder_dialog.rename_title : t.folder_dialog.create_title }}</DialogTitle>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField id="media-folder-name" :label="t.folder_dialog.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="media-folder-name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <FormField v-if="!folder" id="media-folder-parent" :label="t.folder_dialog.parent" :error="form.errors.parent_id" v-slot="{ invalid, describedBy }">
          <FolderSelect id="media-folder-parent" v-model="form.parent_id" :folders="tree" :root-label="t.root" :invalid="invalid" :described-by="describedBy" />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
