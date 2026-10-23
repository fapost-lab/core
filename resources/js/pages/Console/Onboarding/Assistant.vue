<script lang="ts">
import OnboardingLayout from '../../../console/onboarding/OnboardingLayout.vue'

export default { layout: OnboardingLayout }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import type { SelectOption } from '../TenantSettings/types'
import type { OnboardingPageProps } from '../../../console/onboarding/types'
import Notice from './Notice.vue'

/** The first assistant step of the setup wizard: a name and a language chosen among the tenant's available ones. */
const props = defineProps<{
  languageOptions: SelectOption[]
  defaultLanguage: string
  storeUrl: string
}>()

const page = usePage<OnboardingPageProps>()
const t = computed(() => page.props.translations.console.onboarding.assistant)
const step = computed(() => page.props.translations.console.onboarding.steps.first_assistant)

const form = useForm({ name: '', default_language: props.defaultLanguage })

function submit(): void {
  form.post(props.storeUrl)
}
</script>

<template>
  <Head :title="step.title" />

  <div class="flex flex-col gap-5">
    <div>
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground mt-1 text-sm">{{ t.description }}</p>
    </div>

    <Notice :title="t.notice_title" :text="t.notice" />

    <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <FormSection :title="step.title" :description="step.description">
        <FormField id="name" :label="t.name" :hint="t.name_help" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="name" v-model="form.name" required maxlength="255" autofocus :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <FormField id="default_language" :label="t.language" :hint="t.language_help" :error="form.errors.default_language" v-slot="{ invalid, describedBy }">
          <Select v-model="form.default_language">
            <SelectTrigger id="default_language" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
              <SelectValue :placeholder="t.placeholder" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="language in languageOptions" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
            </SelectContent>
          </Select>
        </FormField>
      </FormSection>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? t.creating : t.create }}</Button>
      </div>
    </form>
  </div>
</template>
