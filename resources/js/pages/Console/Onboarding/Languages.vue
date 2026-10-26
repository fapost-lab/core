<script lang="ts">
import OnboardingLayout from '../../../console/onboarding/OnboardingLayout.vue'

export default { layout: OnboardingLayout }
</script>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { Lock } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { OptionsPicker } from '@fapost/ui/components/options-picker'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { interpolate } from '@console/shell'
import { fallbackOptions, firstError, keptFallback, withBase } from '../TenantSettings/form'
import type { SelectOption } from '../TenantSettings/types'
import type { OnboardingPageProps } from '../../../console/onboarding/types'
import Notice from './Notice.vue'

/**
 * The content languages step of the setup wizard: the same three fields as the tenant settings (base, available,
 * fallback). The base language is shown read-only once a flow has been published; the server refuses a change as well.
 */
const props = defineProps<{
  languages: { base: string; available: string[]; fallback: string }
  options: SelectOption[]
  baseLanguageLocked: boolean
  /** The tenant is already populated: the stored languages can be confirmed unchanged ("skip"). */
  canKeep: boolean
  updateUrl: string
}>()

const page = usePage<OnboardingPageProps>()
const t = computed(() => page.props.translations.console.onboarding.languages)
const step = computed(() => page.props.translations.console.onboarding.steps.languages)
const skip = computed(() => page.props.translations.console.onboarding.skip)

const form = useForm({
  content_base_language: props.languages.base,
  available_languages: withBase(props.languages.base, [...props.languages.available]),
  fallback_language: props.languages.fallback,
})

const errors = computed(() => form.errors as Record<string, string | undefined>)
const fallbackChoices = computed(() => fallbackOptions(props.options, form.available_languages))

// The base language stays among the available ones, as the server stores it: choosing a base adds it there.
watch([() => form.content_base_language, () => form.available_languages], ([base, available]) => {
  const kept = withBase(base, available)

  if (kept !== available) {
    form.available_languages = kept
  }
})

watch(
  () => form.available_languages,
  (available) => {
    form.fallback_language = keptFallback(form.fallback_language, available)
  },
)

function submit(): void {
  form.put(props.updateUrl)
}

/** Confirms the stored languages as they are, whatever was changed on the page. */
function keep(): void {
  form
    .transform(() => ({
      content_base_language: props.languages.base,
      available_languages: [...props.languages.available],
      fallback_language: props.languages.fallback,
    }))
    .put(props.updateUrl, { onFinish: () => form.transform((data) => data) })
}
</script>

<template>
  <Head :title="step.title" />

  <div class="flex flex-col gap-5">
    <div>
      <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
        <Button v-if="canKeep" type="button" variant="outline" :disabled="form.processing" @click="keep">{{ skip }}</Button>
      </div>
      <p class="text-muted-foreground mt-1 text-sm">{{ t.description }}</p>
    </div>

    <Notice :title="t.notice_title" :text="t.notice" />

    <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <FormSection :title="step.title" :description="step.description">
        <FormField
          id="content_base_language"
          :label="t.base_language"
          :hint="baseLanguageLocked ? t.base_language_locked : t.base_language_help"
          :error="form.errors.content_base_language"
          v-slot="{ invalid, describedBy }"
        >
          <div class="flex items-center gap-2">
            <Select v-model="form.content_base_language" :disabled="baseLanguageLocked">
              <SelectTrigger id="content_base_language" class="min-w-0 flex-1" :aria-invalid="invalid" :aria-describedby="describedBy">
                <SelectValue :placeholder="t.placeholder" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="language in options" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
              </SelectContent>
            </Select>
            <Lock v-if="baseLanguageLocked" class="text-muted-foreground size-4" aria-hidden="true" />
          </div>
        </FormField>

        <FormField
          id="available_languages"
          :label="t.available_languages"
          :hint="t.available_help"
          :error="firstError(errors, 'available_languages')"
          v-slot="{ invalid, describedBy }"
        >
          <OptionsPicker
            id="available_languages"
            v-model="form.available_languages"
            :options="options"
            :placeholder="t.multi_placeholder"
            :search-label="t.search"
            :empty-label="t.nothing_found"
            :selected-label="(count) => interpolate(t.selected, { count })"
            :remove-label="(label) => `${t.remove}: ${label}`"
            :invalid="invalid"
            :described-by="describedBy"
          />
        </FormField>

        <FormField id="fallback_language" :label="t.fallback_language" :hint="t.fallback_help" :error="form.errors.fallback_language" v-slot="{ invalid, describedBy }">
          <Select v-model="form.fallback_language">
            <SelectTrigger id="fallback_language" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
              <SelectValue :placeholder="t.placeholder" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="language in fallbackChoices" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
            </SelectContent>
          </Select>
        </FormField>
      </FormSection>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? t.continuing : t.continue }}</Button>
        <Button v-if="canKeep" type="button" variant="ghost" :disabled="form.processing" @click="keep">{{ skip }}</Button>
      </div>
    </form>
  </div>
</template>
