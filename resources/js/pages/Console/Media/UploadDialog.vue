<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { interpolate } from '@fapost/ui/shell'
import FolderSelect from './FolderSelect.vue'
import type { FolderNode, MediaPageProps } from './types'

/**
 * Uploads several files into a folder (the open one by default). A file outside the size or type limits refuses the
 * batch with a field error; a refusal for the storage limit comes back as a toast, after the files that fit.
 */
const props = defineProps<{
  url: string
  tree: readonly FolderNode[]
  folderId: string | null
  limits: { maxFiles: number; maxSize: string; accept: string }
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)

const input = ref<HTMLInputElement | null>(null)
const form = useForm<{ files: File[]; folder_id: string | null }>({ files: [], folder_id: props.folderId })

// One message for the batch: the error of the list itself, or of the first file that failed.
const filesError = computed(() => {
  const errors = form.errors as Record<string, string | undefined>

  return errors.files ?? Object.entries(errors).find(([key]) => key.startsWith('files.'))?.[1]
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.reset()
    form.clearErrors()
    form.folder_id = props.folderId

    if (input.value) {
      input.value.value = ''
    }
  }
})

function pick(event: Event): void {
  form.files = Array.from((event.target as HTMLInputElement).files ?? [])
}

function submit(): void {
  form.post(props.url, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
    },
  })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t.upload_dialog.title }}</DialogTitle>
        <DialogDescription>{{ interpolate(t.upload_dialog.description, { count: limits.maxFiles, size: limits.maxSize }) }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField id="media-upload-files" :label="t.upload_dialog.files" :error="filesError" v-slot="{ invalid, describedBy }">
          <input
            id="media-upload-files"
            ref="input"
            type="file"
            name="files[]"
            multiple
            required
            :accept="limits.accept || undefined"
            class="border-input bg-card file:text-foreground w-full rounded-md border px-3 py-2 text-sm file:mr-3 file:border-0 file:bg-transparent file:font-semibold"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @change="pick"
          />
        </FormField>

        <FormField id="media-upload-folder" :label="t.upload_dialog.folder" :error="form.errors.folder_id" v-slot="{ invalid, describedBy }">
          <FolderSelect id="media-upload-folder" v-model="form.folder_id" :folders="tree" :root-label="t.root" :invalid="invalid" :described-by="describedBy" />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing || form.files.length === 0">{{ form.processing ? common.saving : t.upload_dialog.submit }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
