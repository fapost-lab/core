<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { Lock } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@fapost/ui/components/tabs'
import { interpolate } from '@fapost/ui/shell'
import CommandsField from './CommandsField.vue'
import MultiSelectPicker from '../Shared/MultiSelectPicker.vue'
import FlowSelect from './FlowSelect.vue'
import KeyValueField from './KeyValueField.vue'
import LocalizedTextField from './LocalizedTextField.vue'
import NewFlowDialog from './NewFlowDialog.vue'
import { errorsUnder, firstError, isTab, tabsWithErrors, toPayload, type SettingsFormData } from './settings'
import type { SettingsOptions, SettingsPageProps, SettingsState, SettingsTab } from './types'

/**
 * The assistant's settings: one form in three tabs, saved as a whole by one button under them. The open tab is kept
 * in the address (`?tab=`), and a tab holding a server error is marked and opened. A flow created from inside the form
 * is sent with the whole form, so nothing typed is lost on the way to the builder.
 */
const props = defineProps<{
  settings: SettingsState
  languageLocked: boolean
  languages: string[]
  options: SettingsOptions
  limit: { reached: boolean; hint: string | null }
  can: { createFlow: boolean }
  urls: { submit: string; storeFlow: string }
}>()

const page = usePage<SettingsPageProps>()
const t = computed(() => page.props.translations.console.settings)
const common = computed(() => page.props.translations.console.form)

const form = useForm<SettingsFormData>({
  default_language: props.settings.defaultLanguage,
  available_countries: props.settings.availableCountries,
  default_flow_id: props.settings.defaultFlowId,
  fallback_message: props.settings.fallbackMessage,
  busy_message: props.settings.busyMessage,
  commands: props.settings.commands.map((command, index) => ({ ...command, key: `stored-${index}` })),
  settings: props.settings.settings,
})

const errors = computed(() => form.errors as Record<string, string | undefined>)
const errorTabs = computed(() => tabsWithErrors(errors.value))

function tabFromUrl(): SettingsTab {
  const tab = new URL(page.url, 'http://localhost').searchParams.get('tab')

  return isTab(tab) ? tab : 'general'
}

const tab = ref<SettingsTab>(tabFromUrl())

// The tab goes into the address without a visit, keeping Inertia's history state, so a reload opens it again.
watch(tab, (value) => {
  const url = new URL(window.location.href)

  if (value === 'general') {
    url.searchParams.delete('tab')
  } else {
    url.searchParams.set('tab', value)
  }

  window.history.replaceState(window.history.state, '', url)
})

watch(errorTabs, (tabs) => {
  if (tabs.length > 0 && !tabs.includes(tab.value)) {
    tab.value = tabs[0]
  }
})

function save(): void {
  form.transform((data) => toPayload(data)).put(props.urls.submit, { preserveScroll: true })
}

/* A flow made from inside the form: for the default flow (`null`) or for the command at that position. */
const newFlowOpen = ref(false)
const newFlowFor = ref<number | null>(null)

function openNewFlow(commandIndex: number | null): void {
  newFlowFor.value = commandIndex
  newFlowOpen.value = true
}

function createFlow(name: string): void {
  const target = newFlowFor.value

  form
    .transform((data) => ({
      ...toPayload(data),
      new_flow_name: name,
      new_flow_target: target === null ? 'default' : 'command',
      ...(target === null ? {} : { command_index: target }),
    }))
    .post(props.urls.storeFlow, {
      preserveScroll: true,
      onError: (received) => {
        // Only an error on the name keeps the dialog open; the rest belongs to the form behind it.
        if (!('new_flow_name' in received)) {
          newFlowOpen.value = false
        }
      },
      onSuccess: () => {
        newFlowOpen.value = false
      },
    })
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
          <TabsTrigger v-for="name in ['general', 'commands', 'advanced'] as const" :key="name" :value="name" :aria-invalid="errorTabs.includes(name)">
            {{ t.tabs[name] }}
            <Badge v-if="name === 'commands' && form.commands.length > 0" variant="neutral">{{ form.commands.length }}</Badge>
            <span v-if="errorTabs.includes(name)" class="bg-destructive size-1.5 rounded-full" :title="t.errors.has_errors" />
            <span v-if="errorTabs.includes(name)" class="sr-only">({{ t.errors.has_errors }})</span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="general" class="flex flex-col gap-5">
          <FormSection :title="t.sections.language.title" :description="t.sections.language.description">
            <FormField
              id="default_language"
              :label="t.fields.default_language"
              :hint="languageLocked ? t.fields.default_language_locked : undefined"
              :error="form.errors.default_language"
              v-slot="{ invalid, describedBy }"
            >
              <div class="flex items-center gap-2">
                <Select v-model="form.default_language" :disabled="languageLocked">
                  <SelectTrigger id="default_language" class="min-w-0 flex-1" :aria-invalid="invalid" :aria-describedby="describedBy">
                    <SelectValue :placeholder="t.fields.language_placeholder" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="language in options.languages" :key="language.value" :value="language.value">{{ language.label }}</SelectItem>
                  </SelectContent>
                </Select>
                <Lock v-if="languageLocked" class="text-muted-foreground size-4" aria-hidden="true" />
              </div>
            </FormField>

            <FormField
              id="available_countries"
              :label="t.fields.available_countries"
              :hint="t.fields.available_countries_help"
              :error="firstError(errors, 'available_countries')"
              v-slot="{ invalid, describedBy }"
            >
              <MultiSelectPicker
                id="available_countries"
                v-model="form.available_countries"
                :options="options.countries"
                :placeholder="t.fields.countries_placeholder"
                :search-label="t.fields.search"
                :empty-label="t.fields.nothing_found"
                :selected-label="(count) => interpolate(t.fields.countries_selected, { count })"
                :remove-label="(label) => `${t.fields.clear}: ${label}`"
                :invalid="invalid"
                :described-by="describedBy"
              />
            </FormField>
          </FormSection>

          <FormSection :title="t.sections.flow.title" :description="t.sections.flow.description">
            <FormField
              id="default_flow_id"
              :label="t.fields.default_flow"
              :hint="settings.defaultFlowMissing && form.default_flow_id === null ? t.fields.flow_missing : (limit.hint ?? undefined)"
              :error="form.errors.default_flow_id"
              v-slot="{ invalid, describedBy }"
            >
              <FlowSelect
                id="default_flow_id"
                v-model="form.default_flow_id"
                :options="options.flows"
                :placeholder="t.fields.flow_placeholder"
                :inactive-label="t.fields.flow_inactive"
                :create-label="t.new_flow.open"
                :can-create="can.createFlow"
                clearable
                :invalid="invalid"
                :described-by="describedBy"
                @create="openNewFlow(null)"
              />
            </FormField>
          </FormSection>

          <FormSection :title="t.sections.messages.title" :description="t.sections.messages.description">
            <FormField id="fallback_message" :label="t.fields.fallback_message">
              <LocalizedTextField id="fallback_message" v-model="form.fallback_message" :label="t.fields.fallback_message" :languages="languages" :errors="errorsUnder(errors, 'fallback_message')" />
            </FormField>
            <FormField id="busy_message" :label="t.fields.busy_message" :hint="t.fields.busy_message_help">
              <LocalizedTextField id="busy_message" v-model="form.busy_message" :label="t.fields.busy_message" :languages="languages" :errors="errorsUnder(errors, 'busy_message')" />
            </FormField>
          </FormSection>
        </TabsContent>

        <TabsContent value="commands">
          <FormSection :title="t.sections.commands.title" :description="t.sections.commands.description">
            <CommandsField
              v-model="form.commands"
              :languages="languages"
              :types="options.commandTypes"
              :flows="options.flows"
              :can-create-flow="can.createFlow"
              :errors="errors"
              @create-flow="(index) => openNewFlow(index)"
            />
          </FormSection>
        </TabsContent>

        <TabsContent value="advanced">
          <FormSection :title="t.sections.advanced.title" :description="t.sections.advanced.description">
            <KeyValueField v-model="form.settings" :errors="errors" />
          </FormSection>
        </TabsContent>
      </Tabs>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      </div>
    </form>

    <NewFlowDialog v-if="can.createFlow" v-model:open="newFlowOpen" :processing="form.processing" :error="errors.new_flow_name ?? errors.command_index" @submit="createFlow" />
  </div>
</template>
