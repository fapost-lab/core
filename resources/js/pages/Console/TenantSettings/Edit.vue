<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { Lock } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@fapost/ui/components/tabs'
import { Textarea } from '@fapost/ui/components/textarea'
import { interpolate } from '@fapost/ui/shell'
import CountriesPicker from '../Settings/CountriesPicker.vue'
import { fallbackOptions, firstError, isTab, keptFallback, tabsWithErrors } from './form'
import type { SelectOption, TenantSettingsPageProps, TenantSettingsState, TenantSettingsTab } from './types'

/**
 * The tenant's settings: one form in three tabs, saved as a whole by one button under them. The open tab is kept in
 * the address (`?tab=`), and a tab holding a server error is marked and opened. The content base language is shown
 * locked once the tenant has flows; the server refuses a change to it as well.
 */
const props = defineProps<{
  settings: TenantSettingsState
  baseLanguageLocked: boolean
  options: { languages: SelectOption[] }
  urls: { submit: string }
}>()

const page = usePage<TenantSettingsPageProps>()
const t = computed(() => page.props.translations.console.tenant_settings)
const common = computed(() => page.props.translations.console.form)

const form = useForm<TenantSettingsState>({ ...props.settings, available_languages: [...props.settings.available_languages] })

const errors = computed(() => form.errors as Record<string, string | undefined>)
const errorTabs = computed(() => tabsWithErrors(errors.value))
const fallbackChoices = computed(() => fallbackOptions(props.options.languages, form.available_languages))
const tabs: TenantSettingsTab[] = ['languages', 'runtime', 'broadcasts']

watch(
  () => form.available_languages,
  (available) => {
    form.fallback_language = keptFallback(form.fallback_language, available)
  },
)

function tabFromUrl(): TenantSettingsTab {
  const tab = new URL(page.url, 'http://localhost').searchParams.get('tab')

  return isTab(tab) ? tab : 'languages'
}

const tab = ref<TenantSettingsTab>(tabFromUrl())

// The tab goes into the address without a visit, keeping Inertia's history state, so a reload opens it again.
watch(tab, (value) => {
  const url = new URL(window.location.href)

  if (value === 'languages') {
    url.searchParams.delete('tab')
  } else {
    url.searchParams.set('tab', value)
  }

  window.history.replaceState(window.history.state, '', url)
})

watch(errorTabs, (withErrors) => {
  if (withErrors.length > 0 && !withErrors.includes(tab.value)) {
    tab.value = withErrors[0]
  }
})

function save(): void {
  form.put(props.urls.submit, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div>
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground mt-1 text-sm">{{ t.description }}</p>
    </div>

    <form class="flex flex-col gap-5" novalidate @submit.prevent="save">
      <Tabs v-model="tab" class="gap-5">
        <TabsList>
          <TabsTrigger v-for="name in tabs" :key="name" :value="name" :aria-invalid="errorTabs.includes(name)">
            {{ t.tabs[name] }}
            <span v-if="errorTabs.includes(name)" class="bg-destructive size-1.5 rounded-full" :title="t.errors.has_errors" />
            <span v-if="errorTabs.includes(name)" class="sr-only">({{ t.errors.has_errors }})</span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="languages">
          <FormSection :title="t.sections.languages.title" :description="t.sections.languages.description">
            <FormField
              id="content_base_language"
              :label="t.fields.content_base_language"
              :hint="baseLanguageLocked ? t.fields.content_base_language_locked : t.fields.content_base_language_help"
              :error="form.errors.content_base_language"
              v-slot="{ invalid, describedBy }"
            >
              <div class="flex items-center gap-2">
                <Select v-model="form.content_base_language" :disabled="baseLanguageLocked">
                  <SelectTrigger id="content_base_language" class="min-w-0 flex-1" :aria-invalid="invalid" :aria-describedby="describedBy">
                    <SelectValue :placeholder="t.fields.language_placeholder" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="language in options.languages" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
                  </SelectContent>
                </Select>
                <Lock v-if="baseLanguageLocked" class="text-muted-foreground size-4" aria-hidden="true" />
              </div>
            </FormField>

            <FormField
              id="available_languages"
              :label="t.fields.available_languages"
              :hint="t.fields.available_languages_help"
              :error="firstError(errors, 'available_languages')"
              v-slot="{ invalid, describedBy }"
            >
              <CountriesPicker
                id="available_languages"
                v-model="form.available_languages"
                :options="options.languages"
                :placeholder="t.fields.languages_placeholder"
                :search-label="t.fields.search"
                :empty-label="t.fields.nothing_found"
                :selected-label="(count) => interpolate(t.fields.languages_selected, { count })"
                :remove-label="(label) => `${t.fields.remove}: ${label}`"
                :invalid="invalid"
                :described-by="describedBy"
              />
            </FormField>

            <FormField
              id="fallback_language"
              :label="t.fields.fallback_language"
              :hint="t.fields.fallback_language_help"
              :error="form.errors.fallback_language"
              v-slot="{ invalid, describedBy }"
            >
              <Select v-model="form.fallback_language">
                <SelectTrigger id="fallback_language" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
                  <SelectValue :placeholder="t.fields.language_placeholder" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="language in fallbackChoices" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
                </SelectContent>
              </Select>
            </FormField>
          </FormSection>
        </TabsContent>

        <TabsContent value="runtime" class="flex flex-col gap-5">
          <FormSection :title="t.sections.messaging.title" :description="t.sections.messaging.description">
            <FormField id="messaging_rate_limit" :label="t.fields.messaging_rate_limit" :error="form.errors.messaging_rate_limit" v-slot="{ invalid, describedBy }">
              <Input
                id="messaging_rate_limit"
                v-model="form.messaging_rate_limit"
                type="number"
                inputmode="numeric"
                min="1"
                required
                class="w-32"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
              />
            </FormField>
          </FormSection>

          <FormSection :title="t.sections.flow.title" :description="t.sections.flow.description">
            <FormField
              id="flow_session_ttl"
              :label="t.fields.flow_session_ttl"
              :hint="t.fields.flow_session_ttl_help"
              :error="form.errors.flow_session_ttl"
              v-slot="{ invalid, describedBy }"
            >
              <Input
                id="flow_session_ttl"
                v-model="form.flow_session_ttl"
                type="number"
                inputmode="numeric"
                min="60"
                required
                class="w-40"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
              />
            </FormField>

            <FormField id="max_retry_attempts" :label="t.fields.max_retry_attempts" :error="form.errors.max_retry_attempts" v-slot="{ invalid, describedBy }">
              <Input
                id="max_retry_attempts"
                v-model="form.max_retry_attempts"
                type="number"
                inputmode="numeric"
                min="0"
                required
                class="w-32"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
              />
            </FormField>

            <FormField
              id="flow_fallback_message"
              :label="t.fields.flow_fallback_message"
              :hint="t.fields.flow_fallback_message_help"
              :error="form.errors.flow_fallback_message"
              v-slot="{ invalid, describedBy }"
            >
              <Textarea id="flow_fallback_message" v-model="form.flow_fallback_message" :rows="2" :aria-invalid="invalid" :aria-describedby="describedBy" />
            </FormField>
          </FormSection>
        </TabsContent>

        <TabsContent value="broadcasts">
          <FormSection :title="t.sections.broadcasts.title" :description="t.sections.broadcasts.description">
            <FormField id="broadcast_chunk_size" :label="t.fields.broadcast_chunk_size" :error="form.errors.broadcast_chunk_size" v-slot="{ invalid, describedBy }">
              <Input
                id="broadcast_chunk_size"
                v-model="form.broadcast_chunk_size"
                type="number"
                inputmode="numeric"
                min="1"
                required
                class="w-32"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
              />
            </FormField>

            <FormField
              id="broadcast_backpressure"
              :label="t.fields.broadcast_backpressure"
              :hint="t.fields.broadcast_backpressure_help"
              :error="form.errors.broadcast_backpressure"
              v-slot="{ invalid, describedBy }"
            >
              <Switch id="broadcast_backpressure" v-model="form.broadcast_backpressure" class="w-fit" :aria-invalid="invalid" :aria-describedby="describedBy" />
            </FormField>
          </FormSection>
        </TabsContent>
      </Tabs>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      </div>
    </form>
  </div>
</template>
