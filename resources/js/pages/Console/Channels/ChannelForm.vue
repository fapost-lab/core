<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Check, Copy } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import { interpolate } from '@fapost/ui/shell'
import AllowedUpdatesField from './AllowedUpdatesField.vue'
import KeyValueField from './KeyValueField.vue'
import SecretInput from './SecretInput.vue'
import type { ChannelsPageProps, ConfigEntry, EditableChannel, MaxConnections, SelectOption } from './types'

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
  types: SelectOption[]
  telegramUpdates: SelectOption[]
  maxConnections: MaxConnections
  /** The channel being edited; absent when creating. */
  channel?: EditableChannel
}>()

const TELEGRAM = 'telegram'

const page = usePage<ChannelsPageProps>()
const t = computed(() => page.props.translations.console.channels)
const common = computed(() => page.props.translations.console.form)

const editing = computed(() => props.mode === 'edit')

const form = useForm({
  type: props.channel?.type ?? props.types[0]?.value ?? TELEGRAM,
  token: '',
  secret_token: '',
  is_active: props.channel?.isActive ?? true,
  config: {
    allowed_updates: [...(props.channel?.telegram?.allowedUpdates ?? [])],
    max_connections: String(props.channel?.telegram?.maxConnections ?? props.maxConnections.default),
  },
  config_entries: (props.channel?.configEntries ?? []).map((entry): ConfigEntry => ({ ...entry })),
})

const isTelegram = computed(() => form.type === TELEGRAM)

// An update type that is not allowed fails on its own index (`config.allowed_updates.N`), which no field of its own shows.
const allowedUpdatesError = computed(
  () => form.errors['config.allowed_updates'] ?? Object.entries(form.errors).find(([key]) => key.startsWith('config.allowed_updates.'))?.[1],
)

// Only the settings of the channel's type are sent, and a change leaves the type out: it cannot change.
form.transform((data) => {
  const { type, config, config_entries, ...rest } = data

  return {
    ...(editing.value ? {} : { type }),
    ...rest,
    ...(type === TELEGRAM ? { config: { ...config, max_connections: Number(config.max_connections) } } : { config_entries }),
  }
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

    <FormSection :title="t.fields.config">

      <template v-if="isTelegram">
        <FormField id="allowed_updates" :label="t.fields.allowed_updates" :hint="t.fields.allowed_updates_help" :error="allowedUpdatesError">
          <AllowedUpdatesField v-model="form.config.allowed_updates" id-prefix="allowed_updates" :options="telegramUpdates" />
        </FormField>

        <FormField
          id="max_connections"
          :label="t.fields.max_connections"
          :hint="interpolate(t.fields.max_connections_help, { min: maxConnections.min, max: maxConnections.max, default: maxConnections.default })"
          :error="form.errors['config.max_connections']"
          v-slot="{ invalid, describedBy }"
        >
          <Input
            id="max_connections"
            v-model="form.config.max_connections"
            type="number"
            inputmode="numeric"
            :min="maxConnections.min"
            :max="maxConnections.max"
            class="w-32"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
          />
        </FormField>
      </template>

      <FormField v-else id="config_entries" :label="t.fields.config" :error="form.errors.config_entries">
        <KeyValueField v-model="form.config_entries" id-prefix="config_entries" :errors="form.errors" />
      </FormField>
    </FormSection>

    <FormSection :title="t.sections.behaviour.title" :description="t.sections.behaviour.description">
      <FormField id="is_active" :label="t.fields.is_active" :error="form.errors.is_active" v-slot="{ invalid, describedBy }">
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
