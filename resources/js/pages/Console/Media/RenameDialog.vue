<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import type { MediaPageProps, MediaRow } from './types'

/** Renames a file; the stored bytes stay where they are. */
const props = defineProps<{
  row: MediaRow | null
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)
const common = computed(() => page.props.translations.console.form)

const form = useForm({ name: '' })

watch(open, (isOpen) => {
  if (isOpen) {
    form.clearErrors()
    form.name = props.row?.name ?? ''
  }
})

function submit(): void {
  if (props.row) {
    form.patch(props.row.updateUrl, { preserveScroll: true, onSuccess: () => (open.value = false) })
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t.rename_dialog.title }}</DialogTitle>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField id="media-file-name" :label="t.rename_dialog.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="media-file-name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
