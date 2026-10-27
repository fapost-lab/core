<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Check, Copy } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import ChannelSettingsFields from './ChannelSettingsFields.vue'
import ChannelSetupGuide from './ChannelSetupGuide.vue'
import SecretInput from './SecretInput.vue'
import type { ChannelTypeOption, ChannelsPageProps, EditableChannel, SettingField, SettingValue, SettingsByType, SetupByType } from './types'

/**
 * The fields of a channel, shared by the create and the edit screen (and by the tenant-wide admin when it moves).
 * Creating chooses the type; editing shows it and cannot change it. The token and the secret token are never given
 * to the form: when editing they start empty and an empty one keeps what is stored. The server validates, and its
 * errors show under the fields.
 */
const props = defineProps<{
  mode: 'create' | 'edit'
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
  /** The label of the link beside the save button; the common "cancel" when absent. */
  cancelLabel?: string
  types: ChannelTypeOption[]
  /** The settings every channel type declared, by type. */
  settings: SettingsByType
  /** The setup guide of every channel type, by type; a created channel's own guide (with its webhook URL) is in `channel`. */
  setup?: SetupByType
  /** The channel being edited; absent when creating. */
  channel?: EditableChannel
}>()

const page = usePage<ChannelsPageProps>()
const t = computed(() => page.props.translations.console.channels)
const common = computed(() => page.props.translations.console.form)

const editing = computed(() => props.mode === 'edit')

// What a field starts with: the stored value when editing, else its default. A number is typed as text.
function initial(field: SettingField, stored: Record<string, SettingValue> | undefined): SettingValue {
  const value = stored?.[field.key] ?? field.default

  if (Array.isArray(value)) {
    return [...value]
  }

  return field.type === 'integer' && value !== null ? String(value) : value
}

function initialConfig(type: string): Record<string, SettingValue> {
  const stored = props.channel?.type === type ? props.channel.settings : undefined

  return Object.fromEntries((props.settings[type] ?? []).map((field) => [field.key, initial(field, stored)]))
}

const form = useForm({
  type: props.channel?.type ?? props.types[0]?.value ?? '',
  token: '',
  secret_token: '',
  is_active: props.channel?.isActive ?? true,
  config: initialConfig(props.channel?.type ?? props.types[0]?.value ?? ''),
})

const setupSteps = computed(() => (editing.value && props.channel ? props.channel.setup : (props.setup?.[form.type] ?? [])))
const fields = computed(() => props.settings[form.type] ?? [])
const usesSecretToken = computed(() => props.types.find((option) => option.value === form.type)?.usesSecretToken ?? true)

// Another type has other settings, so the form starts them from their defaults.
watch(
  () => form.type,
  (type) => {
    form.config = initialConfig(type)
  },
)

// Only the settings of the channel's type are sent, typed (an empty one is left out), and a change leaves the type out: it cannot change.
form.transform((data) => {
  const { type, config, ...rest } = data
  const typed: Record<string, SettingValue> = {}

  for (const field of props.settings[type] ?? []) {
    const value = config[field.key]

    if (value === '' || value === null || value === undefined) {
      continue
    }

    typed[field.key] = field.type === 'integer' ? Number(value) : value
  }

  return { ...(editing.value ? {} : { type }), ...rest, config: typed }
})

const copied = ref(false)

async function copyHash(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.channel?.webhookHash ?? '')
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // The field is read-only text the user can still select by hand.
  }
}

function submit(): void {
  form.submit(props.method, props.submitUrl, { preserveScroll: true })
}
</script>

<template>
  <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
    <FormSection :title="t.sections.connection.title" :description="t.sections.connection.description">
      <FormField v-if="!editing" id="type" :label="t.fields.type" :error="form.errors.type" v-slot="{ invalid, describedBy }">
        <Select v-model="form.type">
          <SelectTrigger id="type" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="option in types" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <div v-else class="grid gap-2">
        <span class="text-[13px] leading-none font-semibold">{{ t.fields.type }}</span>
        <div class="flex items-center gap-2">
          <Badge variant="info">{{ channel?.typeLabel }}</Badge>
          <a v-if="channel?.url" :href="channel.url" target="_blank" rel="noopener" class="text-sm underline-offset-4 hover:underline">{{ channel.handle }}</a>
        </div>
        <p class="text-muted-foreground text-sm">{{ t.fields.type_locked }}</p>
      </div>

      <FormField id="token" :label="t.fields.token" :hint="editing ? t.fields.token_keep : undefined" :error="form.errors.token" v-slot="{ invalid, describedBy }">
        <SecretInput id="token" v-model="form.token" name="bot_token" :required="!editing" :invalid="invalid" :described-by="describedBy" />
      </FormField>

      <FormField
        v-if="usesSecretToken"
        id="secret_token"
        :label="t.fields.secret_token"
        :hint="editing ? `${t.fields.secret_token_help} ${t.fields.secret_token_keep}` : t.fields.secret_token_help"
        :error="form.errors.secret_token"
        v-slot="{ invalid, describedBy }"
      >
        <SecretInput id="secret_token" v-model="form.secret_token" name="webhook_secret" generatable :required="!editing" :invalid="invalid" :described-by="describedBy" />
      </FormField>

      <FormField v-if="editing && channel" id="webhook_hash" :label="t.fields.webhook_hash" :hint="t.fields.webhook_hash_help">
        <div class="flex items-center gap-2">
          <Input id="webhook_hash" :model-value="channel.webhookHash" readonly class="font-mono" aria-describedby="webhook_hash-hint" />
          <Button type="button" variant="outline" class="shrink-0" @click="copyHash">
            <Check v-if="copied" aria-hidden="true" />
            <Copy v-else aria-hidden="true" />
            {{ copied ? t.fields.copied : t.fields.copy }}
          </Button>
        </div>
      </FormField>

    </FormSection>

    <ChannelSetupGuide :steps="setupSteps" />

    <FormSection v-if="fields.length > 0" :title="t.fields.config">
      <ChannelSettingsFields v-model="form.config" :fields="fields" :errors="form.errors" />
    </FormSection>

    <FormSection :title="t.sections.behaviour.title" :description="t.sections.behaviour.description">
      <FormField id="is_active" :label="t.fields.is_active" :error="form.errors.is_active" v-slot="{ invalid, describedBy }">
        <Switch id="is_active" v-model="form.is_active" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>
    </FormSection>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ cancelLabel ?? common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
