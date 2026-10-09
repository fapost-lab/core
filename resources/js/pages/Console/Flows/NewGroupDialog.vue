<script setup lang="ts">
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import type { FlowsPageProps } from './types'

/**
 * Creates a flow group without leaving the flow's form. The server answers with a redirect back to the same page, so the
 * page keeps its state (the form behind this dialog stays as typed), gets the refreshed list of groups, and carries the
 * new group's id as flash data, which this dialog hands to the form.
 */
const props = defineProps<{
  submitUrl: string
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  created: [groupId: string]
}>()

const page = usePage<FlowsPageProps>()
const t = computed(() => page.props.translations.console.flows)
const common = computed(() => page.props.translations.console.form)

const form = useForm({ name: '' })

function submit(): void {
  form.post(props.submitUrl, {
    preserveState: true,
    preserveScroll: true,
    onSuccess: (updated) => {
      const id = (updated.flash as { flowGroupId?: string } | undefined)?.flowGroupId

      form.reset()
      open.value = false

      if (id) {
        emit('created', id)
      }
    },
  })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t.new_group_title }}</DialogTitle>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField id="new-group-name" :label="t.new_group_name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="new-group-name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : t.new_group_save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
