<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import type { SettingsPageProps } from './types'

/**
 * Asks for the name of a flow created from inside the settings form. The page sends it together with the whole form:
 * the settings are saved with the new flow chosen, and the flow opens in the builder. A refusal (the flow limit, an
 * invalid form) comes back as a toast or as errors on the form.
 */
defineProps<{
  processing: boolean
  error?: string
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{ submit: [name: string] }>()

const page = usePage<SettingsPageProps>()
const t = computed(() => page.props.translations.console.settings)
const common = computed(() => page.props.translations.console.form)

const name = ref('')

watch(open, (isOpen) => {
  if (isOpen) {
    name.value = ''
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t.new_flow.title }}</DialogTitle>
        <DialogDescription>{{ t.new_flow.hint }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="emit('submit', name)">
        <FormField id="new-flow-name" :label="t.new_flow.name" :error="error" v-slot="{ invalid, describedBy }">
          <Input id="new-flow-name" v-model="name" name="new_flow_name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="processing">{{ processing ? common.saving : t.new_flow.submit }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
