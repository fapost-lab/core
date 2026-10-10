<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { interpolate } from '@fapost/ui/shell'
import FolderSelect from './FolderSelect.vue'
import { oversized, uploadSequentially, type UploadOutcome } from './upload'
import type { FolderNode, MediaPageProps } from './types'

/**
 * Uploads several files into a folder (the open one by default), one request per file, so a batch is never refused
 * for its total size. Files over the size limit are refused here before anything is sent. The server answers the last
 * request (or the one refused for the storage limit) for the whole batch, as one toast; a file outside the type list
 * stops the batch with its error shown here, the files before it stay stored.
 */
const props = defineProps<{
  url: string
  tree: readonly FolderNode[]
  folderId: string | null
  limits: { maxFiles: number; maxBytes: number; maxSize: string; accept: string }
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)

const input = ref<HTMLInputElement | null>(null)
const files = ref<File[]>([])
const folderId = ref<string | null>(props.folderId)
const errors = ref<Record<string, string | undefined>>({})
const processing = ref(false)
const progress = ref(0)

const filesError = computed(() => errors.value.files)

watch(open, (isOpen) => {
  if (isOpen) {
    files.value = []
    errors.value = {}
    progress.value = 0
    folderId.value = props.folderId

    if (input.value) {
      input.value.value = ''
    }
  }
})

function pick(event: Event): void {
  files.value = Array.from((event.target as HTMLInputElement).files ?? [])
  errors.value = {}
}

function check(): string | null {
  if (files.value.length > props.limits.maxFiles) {
    return interpolate(t.value.upload_dialog.too_many, { count: props.limits.maxFiles })
  }

  const tooLarge = oversized(files.value, props.limits.maxBytes)

  return tooLarge.length > 0 ? interpolate(t.value.upload_dialog.too_large, { names: tooLarge.join(', '), size: props.limits.maxSize }) : null
}

function send(file: File, index: number, total: number, savedBefore: number): Promise<UploadOutcome> {
  progress.value = index + 1

  return new Promise((resolve) => {
    let outcome: UploadOutcome = 'invalid'

    router.post(
      props.url,
      { files: [file], folder_id: folderId.value, batch_total: total, batch_saved: savedBefore },
      {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: (updated) => {
          outcome = (updated.flash as { error?: string | null } | undefined)?.error ? 'refused' : 'saved'
        },
        onError: (received) => {
          // The error belongs to this file: say which one.
          const message = received.files ?? Object.entries(received).find(([key]) => key.startsWith('files'))?.[1]
          errors.value = { ...received, files: message ? `${file.name}: ${message}` : undefined }
        },
        onFinish: () => resolve(outcome),
      },
    )
  })
}

async function submit(): Promise<void> {
  const refusal = check()

  if (refusal) {
    errors.value = { files: refusal }

    return
  }

  processing.value = true
  errors.value = {}

  try {
    const result = await uploadSequentially(files.value, send)

    // Stored, or stopped by the storage limit (a toast says so): done. A field error keeps the dialog open.
    if (result.stoppedAt === null || Object.keys(errors.value).length === 0) {
      open.value = false
    }
  } finally {
    processing.value = false
  }
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

        <FormField id="media-upload-folder" :label="t.upload_dialog.folder" :error="errors.folder_id" v-slot="{ invalid, describedBy }">
          <FolderSelect id="media-upload-folder" v-model="folderId" :folders="tree" :root-label="t.root" :invalid="invalid" :described-by="describedBy" />
        </FormField>

        <p v-if="processing" class="text-muted-foreground text-sm" role="status">
          {{ interpolate(t.upload_dialog.progress, { current: progress, total: files.length }) }}
        </p>

        <DialogFooter>
          <Button type="button" variant="ghost" :disabled="processing" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="processing || files.length === 0">{{ processing ? common.saving : t.upload_dialog.submit }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
