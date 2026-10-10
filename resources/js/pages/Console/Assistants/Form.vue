<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import type { AssistantFields, AssistantsPageProps, LanguageOption } from './types'

/** The fields of an assistant, shared by the create and the edit screen. The server validates; its errors show under the fields. */
const props = defineProps<{
  initial: AssistantFields
  languages: LanguageOption[]
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
}>()

const page = usePage<AssistantsPageProps>()
const t = computed(() => page.props.translations.console.assistants)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
  default_language: props.initial.defaultLanguage,
  is_active: props.initial.isActive,
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

      <FormField id="default_language" :label="t.fields.default_language" :error="form.errors.default_language" v-slot="{ invalid, describedBy }">
        <Select v-model="form.default_language">
          <SelectTrigger id="default_language" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue :placeholder="t.fields.language_placeholder" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="language in languages" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField id="is_active" :label="t.fields.is_active" :hint="t.fields.is_active_help" :error="form.errors.is_active" v-slot="{ invalid, describedBy }">
        <Switch id="is_active" v-model="form.is_active" class="w-fit" :aria-invalid="invalid" :aria-describedby="describedBy" />
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
