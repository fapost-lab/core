<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { interpolate } from '@fapost/ui/shell'
import FolderSelect from './FolderSelect.vue'
import type { FolderNode, MediaPageProps } from './types'

/** Moves one file or the ticked ones into a folder, or to the root. */
const props = defineProps<{
  url: string
  ids: readonly string[]
  tree: readonly FolderNode[]
  folderId: string | null
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  moved: []
}>()

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)

const form = useForm<{ ids: string[]; folder_id: string | null }>({ ids: [], folder_id: null })

watch(open, (isOpen) => {
  if (isOpen) {
    form.clearErrors()
    form.folder_id = props.folderId
  }
})

function submit(): void {
  form.ids = [...props.ids]
  form.put(props.url, {
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
      emit('moved')
    },
  })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t.move_dialog.title }}</DialogTitle>
        <DialogDescription>{{ interpolate(t.move_dialog.description, { count: ids.length }) }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField id="media-move-folder" :label="t.move_dialog.folder" :error="form.errors.folder_id ?? form.errors.ids" v-slot="{ invalid, describedBy }">
          <FolderSelect id="media-move-folder" v-model="form.folder_id" :folders="tree" :root-label="t.root" :invalid="invalid" :described-by="describedBy" />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : t.move_dialog.submit }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
