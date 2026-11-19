<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { ValueListInput } from '@fapost/ui/components/value-list-input'
import { interpolate } from '@console/shell'
import type { CredentialTypeField, CredentialTypeSchema, CredentialsPageProps, EditableCredential } from './types'

/**
 * The fields of a credential, shared by the create and the edit screen. The type fields come from the `types` prop, one
 * schema per type, so the form knows no type by name (the one exception is the warning for a key sent in the URL, which
 * names the type it is about). Creating chooses the type; editing shows it and cannot change it.
 *
 * A secret field is never given a value: when editing it starts empty and an empty one keeps what is stored. The server
 * validates and its errors show under the fields.
 */
const props = defineProps<{
  mode: 'create' | 'edit'
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
  types: CredentialTypeSchema[]
  /** The credential being edited; absent when creating. */
  credential?: EditableCredential
}>()

const page = usePage<CredentialsPageProps>()
const t = computed(() => page.props.translations.console.credentials)
const common = computed(() => page.props.translations.console.form)

const editing = computed(() => props.mode === 'edit')

const form = useForm({
  name: props.credential?.name ?? '',
  type: props.credential?.type ?? '',
  settings: { ...(props.credential?.settings ?? {}) } as Record<string, string>,
  secrets: {} as Record<string, string>,
  allowed_hosts: [...(props.credential?.allowed_hosts ?? [])],
})

const hosts = ref<InstanceType<typeof ValueListInput> | null>(null)

const schema = computed<CredentialTypeSchema | undefined>(() => props.types.find((type) => type.id === form.type))
const settingFields = computed<CredentialTypeField[]>(() => (schema.value?.fields ?? []).filter((field) => !field.secret))
const secretFields = computed<CredentialTypeField[]>(() => (schema.value?.fields ?? []).filter((field) => field.secret))
const typeLabel = computed(() => schema.value?.label ?? props.credential?.type_label ?? '')

// Another type has other fields: what was typed for the first one is dropped, not sent along.
watch(
  () => form.type,
  () => {
    if (!editing.value) {
      form.settings = {}
      form.secrets = {}
      form.clearErrors()
    }
  },
)

const secretChanged = computed(() => {
  const at = props.credential?.secret_changed_at

  return at ? new Intl.DateTimeFormat(page.props.locale, { dateStyle: 'medium' }).format(new Date(at)) : ''
})

// When editing: that a value is stored (never the value), how to keep it, and when it last changed.
const secretHint = computed(() => {
  if (!editing.value) {
    return undefined
  }

  return secretChanged.value ? `${t.value.fields.secret_saved} ${interpolate(t.value.fields.secret_changed, { date: secretChanged.value })}` : t.value.fields.secret_saved
})

function inputType(field: CredentialTypeField): string {
  return field.kind === 'url' ? 'url' : 'text'
}

function submit(): void {
  hosts.value?.commit()

  const fields = schema.value?.fields ?? []

  form
    .transform((data) => ({
      name: data.name,
      // The type is chosen once: a change is sent without it.
      ...(editing.value ? {} : { type: data.type }),
      settings: Object.fromEntries(fields.filter((field) => !field.secret).map((field) => [field.key, data.settings[field.key] ?? ''])),
      secrets: Object.fromEntries(fields.filter((field) => field.secret).map((field) => [field.key, data.secrets[field.key] ?? ''])),
      allowed_hosts: data.allowed_hosts,
    }))
    .submit(props.method, props.submitUrl, {
      preserveScroll: true,
      // A refused save keeps no secret in the form: the browser's copy is cleared with the rest of the secrets.
      onError: () => {
        form.secrets = {}
      },
    })
}
</script>

<template>
  <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
    <FormSection :title="t.sections.general.title" :description="t.sections.general.description">
      <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
        <Input id="name" v-model="form.name" name="name" required maxlength="120" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField v-if="editing" id="type" :label="t.fields.type" :hint="t.fields.type_locked">
        <Input id="type" :model-value="typeLabel" readonly disabled aria-describedby="type-hint" />
      </FormField>

      <FormField v-else id="type" :label="t.fields.type" :error="form.errors.type" v-slot="{ invalid, describedBy }">
        <Select v-model="form.type">
          <SelectTrigger id="type" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue :placeholder="t.fields.choose" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="type in types" :key="type.id" :value="type.id">{{ type.label }}</SelectItem>
          </SelectContent>
        </Select>
      </FormField>
    </FormSection>

    <FormSection v-if="schema" :title="t.sections.secret.title" :description="t.sections.secret.description">
      <div
        v-if="schema.id === 'api_key_query'"
        role="note"
        data-testid="query-key-warning"
        class="border-warning/40 bg-warning/10 flex items-start gap-2 rounded-md border px-3 py-2 text-[13px]"
      >
        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p>{{ t.warnings.api_key_query }}</p>
      </div>

      <FormField
        v-for="field in settingFields"
        :id="`setting-${field.key}`"
        :key="`${schema.id}-${field.key}`"
        :label="field.label"
        :error="form.errors[`settings.${field.key}`]"
        v-slot="{ invalid, describedBy }"
      >
        <Select v-if="field.kind === 'select'" v-model="form.settings[field.key]">
          <SelectTrigger :id="`setting-${field.key}`" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue :placeholder="t.fields.choose" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="choice in field.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</SelectItem>
          </SelectContent>
        </Select>
        <Input
          v-else
          :id="`setting-${field.key}`"
          v-model="form.settings[field.key]"
          :name="`settings[${field.key}]`"
          :type="inputType(field)"
          :required="field.required"
          maxlength="255"
          autocomplete="off"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
        />
      </FormField>

      <FormField
        v-for="field in secretFields"
        :id="`secret-${field.key}`"
        :key="`${schema.id}-${field.key}`"
        :label="field.label"
        :error="form.errors[`secrets.${field.key}`]"
        :hint="secretHint"
        v-slot="{ invalid, describedBy }"
      >
        <Input
          :id="`secret-${field.key}`"
          v-model="form.secrets[field.key]"
          :name="`secrets[${field.key}]`"
          type="password"
          :required="!editing && field.required"
          :placeholder="editing ? t.fields.secret_placeholder : undefined"
          maxlength="4096"
          autocomplete="new-password"
          data-1p-ignore
          data-lpignore="true"
          data-form-type="other"
          spellcheck="false"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
        />
      </FormField>
    </FormSection>

    <FormSection v-if="schema" :title="t.sections.hosts.title" :description="t.sections.hosts.description">
      <FormField
        id="allowed_hosts"
        :label="t.fields.hosts"
        :error="form.errors.allowed_hosts ?? Object.entries(form.errors).find(([key]) => key.startsWith('allowed_hosts.'))?.[1]"
        v-slot="{ invalid, describedBy }"
      >
        <ValueListInput
          id="allowed_hosts"
          ref="hosts"
          v-model="form.allowed_hosts"
          :placeholder="t.fields.hosts_placeholder"
          :remove-label="(host) => interpolate(t.fields.hosts_remove, { host })"
          :invalid="invalid"
          :described-by="describedBy"
        />
      </FormField>
    </FormSection>

    <p v-else class="text-muted-foreground text-[13px]">{{ t.type_hint }}</p>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing || !schema">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
