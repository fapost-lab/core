<script setup lang="ts">
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import ChoiceListField from './ChoiceListField.vue'
import type { SettingField, SettingValue } from './types'

/**
 * The settings an integration declared for a channel type, one control per field by its type: a text or a number in
 * an input, a boolean in a switch, one choice in a select and a list of choices in checkboxes. Everything the
 * controls say (labels, hints, choices) arrives translated from the server. A number is held as text while it is
 * typed. `errors` are the form's, keyed `config.<key>` (and `config.<key>.N` for one entry of a list).
 */
const props = defineProps<{
  fields: SettingField[]
  errors: Record<string, string | undefined>
}>()

const model = defineModel<Record<string, SettingValue>>({ required: true })

function set(key: string, value: SettingValue): void {
  model.value = { ...model.value, [key]: value }
}

function textOf(key: string): string {
  const value = model.value[key]

  return value === null || value === undefined ? '' : String(value)
}

function listOf(key: string): string[] {
  const value = model.value[key]

  return Array.isArray(value) ? value : []
}

// A rejected entry of a list fails on its own index (`config.<key>.N`), which no field of its own shows.
function errorOf(field: SettingField): string | undefined {
  return props.errors[`config.${field.key}`] ?? Object.entries(props.errors).find(([key]) => key.startsWith(`config.${field.key}.`))?.[1]
}
</script>

<template>
  <FormField
    v-for="field in fields"
    :id="`setting-${field.key}`"
    :key="field.key"
    :label="field.label"
    :hint="field.help ?? undefined"
    :error="errorOf(field)"
    v-slot="{ invalid, describedBy }"
  >
    <Switch
      v-if="field.type === 'boolean'"
      :id="`setting-${field.key}`"
      class="w-fit"
      :model-value="model[field.key] === true"
      :aria-invalid="invalid"
      :aria-describedby="describedBy"
      @update:model-value="(value) => set(field.key, value)"
    />

    <Select v-else-if="field.type === 'choice'" :model-value="textOf(field.key)" @update:model-value="(value) => set(field.key, String(value))">
      <SelectTrigger :id="`setting-${field.key}`" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="choice in field.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</SelectItem>
      </SelectContent>
    </Select>

    <ChoiceListField
      v-else-if="field.type === 'choices'"
      :model-value="listOf(field.key)"
      :id-prefix="`setting-${field.key}`"
      :options="field.choices"
      @update:model-value="(value) => set(field.key, value)"
    />

    <Input
      v-else
      :id="`setting-${field.key}`"
      :model-value="textOf(field.key)"
      :type="field.type === 'integer' ? 'number' : 'text'"
      :inputmode="field.type === 'integer' ? 'numeric' : undefined"
      :min="field.min ?? undefined"
      :max="field.type === 'integer' ? (field.max ?? undefined) : undefined"
      :maxlength="field.type === 'text' ? (field.max ?? undefined) : undefined"
      :class="field.type === 'integer' ? 'w-32' : undefined"
      :aria-invalid="invalid"
      :aria-describedby="describedBy"
      @update:model-value="(value) => set(field.key, String(value))"
    />
  </FormField>
</template>
