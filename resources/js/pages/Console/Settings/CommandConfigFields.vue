<script setup lang="ts">
import { computed, h, type FunctionalComponent } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import { Textarea } from '@fapost/ui/components/textarea'
import { enumChoices, isEditableField, visibleConfigFields, type ConfigField } from './settings'
import type { CommandConfigWarning, ConfigSchema, JsonValue, SettingsPageProps } from './types'

/**
 * The config of a command whose action a Solution registered, as a form built from the action's `configSchema()`.
 *
 * It edits text, long text, number, on/off and choice fields; a field of any other kind is shown as it is stored and
 * sent back unchanged. Every edit spreads the config, so keys the form does not show survive. The Solution's own
 * `validateConfig()` judges the config when it is saved; its errors arrive keyed `config.<field>` (or `config`).
 */
const props = defineProps<{
  /** The id prefix of the command's controls, such as `command-2`. */
  id: string
  schema: ConfigSchema | undefined
  /** The command's errors, keyed relative to it (`config.text`). */
  errors: Record<string, string | undefined>
  warnings: CommandConfigWarning[]
}>()

const config = defineModel<Record<string, JsonValue>>({ required: true })

const page = usePage<SettingsPageProps>()
const t = computed(() => page.props.translations.console.settings)

const fields = computed(() => visibleConfigFields(props.schema, config.value))

function fieldId(field: ConfigField): string {
  return `${props.id}-config-${field.key}`
}

function labelOf(field: ConfigField): string {
  const label = typeof field.schema.label === 'string' && field.schema.label !== '' ? field.schema.label : field.key

  return field.required ? `${label} *` : label
}

function hintOf(field: ConfigField): string | undefined {
  return typeof field.schema.help === 'string' && field.schema.help !== '' ? field.schema.help : undefined
}

function placeholderOf(field: ConfigField): string | undefined {
  return typeof field.schema.placeholder === 'string' ? field.schema.placeholder : undefined
}

function typeOf(field: ConfigField): string {
  return String(field.schema.type ?? 'string')
}

function text(field: ConfigField): string {
  const value = config.value[field.key]

  return value === null || value === undefined ? '' : String(value)
}

function set(field: ConfigField, value: JsonValue): void {
  config.value = { ...config.value, [field.key]: value }
}

function setNumber(field: ConfigField, value: string | number): void {
  const raw = String(value).trim()
  const number = Number(raw)

  // An empty field is no number; anything the browser lets through that is not a number stays as typed, for the
  // Solution's validation to refuse.
  set(field, raw === '' ? null : Number.isFinite(number) ? number : raw)
}

function stored(field: ConfigField): string {
  return JSON.stringify(config.value[field.key] ?? null, null, 2)
}

const shownKeys = computed(() => new Set(fields.value.map((field) => field.key)))

const generalWarnings = computed(() => props.warnings.filter((warning) => warning.field === null || !shownKeys.value.has(warning.field)))

/** The config's own error and those of fields not shown (hidden, nested, or not in the schema): they go above the fields. */
const generalErrors = computed(() =>
  Object.entries(props.errors)
    .filter(([key, message]) => message !== undefined && (key === 'config' || (key.startsWith('config.') && !shownKeys.value.has(key.slice('config.'.length)))))
    .map(([key, message]) => ({ key, message: message as string })),
)

/** One warning of the Solution's validation, in the warning callout of the console's pages. */
const WarningNote: FunctionalComponent<{ message: string }> = (note) =>
  h('div', { class: 'bg-warning text-warning-foreground flex items-start gap-2 rounded-md px-3 py-2 text-[12.5px]', role: 'status' }, [
    h(TriangleAlert, { class: 'mt-px size-3.5 shrink-0', 'aria-hidden': 'true' }),
    h('span', note.message),
  ])

function warningsOf(field: ConfigField): CommandConfigWarning[] {
  return props.warnings.filter((warning) => warning.field === field.key)
}
</script>

<template>
  <div class="grid gap-[18px]">
    <p v-for="error in generalErrors" :key="error.key" role="alert" class="text-destructive text-[12.5px]">{{ error.message }}</p>

    <WarningNote v-for="warning in generalWarnings" :key="`general-${warning.message}`" :message="warning.message" />

    <div v-for="field in fields" :key="field.key" class="grid gap-1.5">
      <FormField :id="fieldId(field)" :label="labelOf(field)" :hint="hintOf(field)" :error="errors[`config.${field.key}`]" v-slot="{ invalid, describedBy }">
        <template v-if="!isEditableField(field)">
          <pre :id="fieldId(field)" class="bg-surface-muted text-muted-foreground max-h-40 overflow-auto rounded-md border px-3 py-2 text-[12.5px]" :aria-describedby="`${fieldId(field)}-unsupported`">{{ stored(field) }}</pre>
          <p :id="`${fieldId(field)}-unsupported`" class="text-muted-foreground text-[12.5px]">{{ t.commands.config_unsupported }}</p>
        </template>

        <Textarea
          v-else-if="typeOf(field) === 'text'"
          :id="fieldId(field)"
          :model-value="text(field)"
          :placeholder="placeholderOf(field)"
          rows="3"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
          @update:model-value="(value) => set(field, String(value))"
        />

        <Input
          v-else-if="typeOf(field) === 'number'"
          :id="fieldId(field)"
          type="number"
          :model-value="text(field)"
          :placeholder="placeholderOf(field)"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
          @update:model-value="(value) => setNumber(field, value)"
        />

        <div v-else-if="typeOf(field) === 'boolean'">
          <Switch :id="fieldId(field)" :model-value="config[field.key] === true" :aria-invalid="invalid" :aria-describedby="describedBy" @update:model-value="(value) => set(field, value === true)" />
        </div>

        <div v-else-if="typeOf(field) === 'enum' || typeOf(field) === 'enum-cards'" class="flex items-center gap-2">
          <Select :model-value="text(field) === '' ? undefined : text(field)" @update:model-value="(value) => set(field, String(value))">
            <SelectTrigger :id="fieldId(field)" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
              <SelectValue :placeholder="placeholderOf(field) ?? labelOf(field)" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="choice in enumChoices(field)" :key="choice.value" :value="choice.value">{{ choice.label }}</SelectItem>
            </SelectContent>
          </Select>
          <Button v-if="!field.required && text(field) !== ''" type="button" variant="ghost" size="sm" @click="set(field, null)">{{ t.fields.clear }}</Button>
        </div>

        <Input
          v-else
          :id="fieldId(field)"
          :model-value="text(field)"
          :placeholder="placeholderOf(field)"
          autocomplete="off"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
          @update:model-value="(value) => set(field, String(value))"
        />
      </FormField>

      <WarningNote v-for="warning in warningsOf(field)" :key="`${field.key}-${warning.message}`" :message="warning.message" />
    </div>
  </div>
</template>
