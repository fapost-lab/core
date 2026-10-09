<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Checkbox } from '@fapost/ui/components/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { Input } from '@fapost/ui/components/input'
import { Label } from '@fapost/ui/components/label'
import type { ContactGroupRef, ContactsPageProps } from './types'

/**
 * Replaces the groups a contact is in. The whole set is sent, so repeating the request changes nothing.
 */
const props = defineProps<{
  groups: ContactGroupRef[]
  options: ContactGroupRef[]
  submitUrl: string
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<ContactsPageProps>()
const t = computed(() => page.props.translations.console.contacts)
const common = computed(() => page.props.translations.console.form)

const form = useForm<{ groups: string[] }>({ groups: props.groups.map((group) => group.id) })
const search = ref('')

// Each opening starts from the groups the contact is in now.
watch(open, (isOpen) => {
  if (isOpen) {
    form.clearErrors()
    form.groups = props.groups.map((group) => group.id)
    search.value = ''
  }
})

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return needle === '' ? props.options : props.options.filter((option) => option.name.toLowerCase().includes(needle))
})

const error = computed(() => Object.entries(form.errors).find(([key]) => key === 'groups' || key.startsWith('groups.'))?.[1])

function toggle(id: string, checked: boolean | 'indeterminate'): void {
  const without = form.groups.filter((existing) => existing !== id)

  form.groups = checked === true ? [...without, id] : without
}

function submit(): void {
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
        <DialogTitle>{{ t.groups_dialog.title }}</DialogTitle>
        <DialogDescription>{{ t.groups_dialog.description }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <p v-if="options.length === 0" class="text-muted-foreground text-sm">{{ t.groups_dialog.empty }}</p>

        <template v-else>
          <Input v-model="search" type="search" autocomplete="off" :aria-label="t.groups_dialog.search" :placeholder="t.groups_dialog.search" />

          <ul class="flex max-h-72 flex-col gap-1 overflow-y-auto">
            <li v-for="option in visible" :key="option.id" class="flex items-center gap-2 rounded-md px-1 py-1.5">
              <Checkbox :id="`contact-group-${option.id}`" :model-value="form.groups.includes(option.id)" @update:model-value="(checked) => toggle(option.id, checked)" />
              <Label :for="`contact-group-${option.id}`" class="flex-1 cursor-pointer font-normal">{{ option.name }}</Label>
            </li>
          </ul>
        </template>

        <p v-if="error" role="alert" class="text-destructive text-sm">{{ error }}</p>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing || options.length === 0">{{ form.processing ? common.saving : common.save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
