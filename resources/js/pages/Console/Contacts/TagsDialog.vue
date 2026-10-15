<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { ValueListInput } from '@fapost/ui/components/value-list-input'
import { interpolate } from '@fapost/ui/shell'
import type { ContactsPageProps } from './types'

/**
 * Replaces a contact's tags. The whole set is sent, so a tag set elsewhere while the dialog was open is removed by
 * saving a set that does not have it; the server keeps the tags that are in the set as they were.
 */
const props = defineProps<{
  tags: string[]
  suggestions: string[]
  submitUrl: string
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<ContactsPageProps>()
const t = computed(() => page.props.translations.console.contacts)
const common = computed(() => page.props.translations.console.form)

const form = useForm({ tags: [...props.tags] })
const tagInput = ref<InstanceType<typeof ValueListInput> | null>(null)

// Each opening starts from the tags the contact has now.
watch(open, (isOpen) => {
  if (isOpen) {
    form.reset()
    form.clearErrors()
    form.tags = [...props.tags]
  }
})

const errors = computed(() => Object.entries(form.errors).filter(([key]) => key === 'tags' || key.startsWith('tags.')).map(([, message]) => message))

// A tag typed and not yet added still counts: the field adds it on blur, and once more here in case it kept focus.
function submit(): void {
  tagInput.value?.commit()
  form.put(props.submitUrl, {
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
        <DialogTitle>{{ t.tags_dialog.title }}</DialogTitle>
        <DialogDescription>{{ t.tags_dialog.description }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField id="contact-tag" :label="t.view.tags" :error="errors[0]" v-slot="{ invalid, describedBy }">
          <ValueListInput
            id="contact-tag"
            ref="tagInput"
            v-model="form.tags"
            :suggestions="suggestions"
            :placeholder="t.tags_dialog.placeholder"
            :remove-label="(tag) => interpolate(t.tags_dialog.remove_named, { tag })"
            :invalid="invalid"
            :described-by="describedBy"
          />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
