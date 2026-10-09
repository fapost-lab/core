<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { X } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { interpolate } from '@fapost/ui/shell'
import { addTags, removeTag } from './tags'
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
const draft = ref('')

// Each opening starts from the tags the contact has now.
watch(open, (isOpen) => {
  if (isOpen) {
    form.reset()
    form.clearErrors()
    form.tags = [...props.tags]
    draft.value = ''
  }
})

// A tag written in the field and not yet added still counts when the form is saved.
function commitDraft(): void {
  form.tags = addTags(form.tags, draft.value)
  draft.value = ''
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()
    commitDraft()
  }
}

const errors = computed(() => Object.entries(form.errors).filter(([key]) => key === 'tags' || key.startsWith('tags.')).map(([, message]) => message))

function submit(): void {
  commitDraft()
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
          <div v-if="form.tags.length" class="flex flex-wrap gap-1.5">
            <Badge v-for="tag in form.tags" :key="tag" variant="neutral" class="gap-1 pr-1">
              <span class="max-w-48 truncate">{{ tag }}</span>
              <button
                type="button"
                class="hover:bg-foreground/10 rounded-full p-0.5"
                :aria-label="interpolate(t.tags_dialog.remove_named, { tag })"
                @click="form.tags = removeTag(form.tags, tag)"
              >
                <X class="size-3" aria-hidden="true" />
              </button>
            </Badge>
          </div>

          <Input
            id="contact-tag"
            v-model="draft"
            maxlength="255"
            autocomplete="off"
            list="contact-tag-suggestions"
            :placeholder="t.tags_dialog.placeholder"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @keydown="onKeydown"
            @blur="commitDraft"
          />
          <datalist id="contact-tag-suggestions">
            <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
          </datalist>
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
