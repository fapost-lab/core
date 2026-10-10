<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Textarea } from '@fapost/ui/components/textarea'
import type { ContactGroupsPageProps } from './types'

/** The fields of a contact group, shared by the create and the edit screen. The server validates; its errors show under the fields. */
const props = defineProps<{
  initial: { name: string; description: string | null }
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
}>()

const page = usePage<ContactGroupsPageProps>()
const t = computed(() => page.props.translations.console.contact_groups)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
  description: props.initial.description ?? '',
})

function submit(): void {
  form.submit(props.method, props.submitUrl, { preserveScroll: true })
}
</script>

<template>
  <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
    <FormSection :title="t.sections.general.title" :description="t.sections.general.description">
      <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
        <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField id="description" :label="t.fields.description" :error="form.errors.description" v-slot="{ invalid, describedBy }">
        <Textarea id="description" v-model="form.description" name="description" rows="4" maxlength="1000" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>
    </FormSection>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
