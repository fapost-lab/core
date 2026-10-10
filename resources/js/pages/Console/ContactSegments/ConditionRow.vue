<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Trash2 } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { interpolate } from '@fapost/ui/shell'
import OptionsPicker from './OptionsPicker.vue'
import ValueListInput from './ValueListInput.vue'
import { arityOf, changeOperator, changeType, isKnown, singleValue, typeOf, withSingleValue } from './rules'
import type { Condition, ContactSegmentsPageProps, SegmentOptions, SegmentSchema } from './types'

/**
 * One condition of a segment: type, (key,) operator and the value input that fits the pair. What each pair takes comes
 * from the schema the server sent; the only thing decided here is which control draws it. The server's errors for this
 * row come in as `errors`, by field.
 */
const props = defineProps<{
  index: number
  schema: SegmentSchema
  options: SegmentOptions
  /** The server's errors for this row, by field (`type`, `key`, `operator`, `value`). */
  errors: Record<string, string>
}>()

const emit = defineEmits<{ remove: [] }>()

const condition = defineModel<Condition>({ required: true })

const t = computed(() => usePage<ContactSegmentsPageProps>().props.translations.console.contact_segments)

const known = computed(() => isKnown(props.schema, condition.value))
const type = computed(() => typeOf(props.schema, condition.value.type))
const arity = computed(() => arityOf(props.schema, condition.value.type, condition.value.operator))
const operators = computed(() => type.value?.operators ?? [])

// A pair the schema does not know is not drawn as a value input: the row says it cannot show it.
const valueKind = computed<'single-text' | 'single-platform' | 'many-text' | 'many-options' | null>(() => {
  if (!known.value || arity.value === 'none' || arity.value === null) {
    return null
  }

  const many = arity.value === 'many'

  if (condition.value.type === 'platform') {
    return many ? 'many-options' : 'single-platform'
  }

  if (condition.value.type === 'group') {
    return 'many-options'
  }

  return many ? 'many-text' : 'single-text'
})

const optionList = computed(() =>
  condition.value.type === 'group'
    ? props.options.groups.map((group) => ({ value: group.id, label: group.name }))
    : props.options.platforms,
)

// Hints for a text input, from what the workspace already has.
const suggestions = computed(() => (condition.value.type === 'language' ? props.options.languages : condition.value.type === 'tag' ? props.options.tags : []))

const id = (field: string): string => `condition-${props.index}-${field}`

const text = computed({
  get: () => singleValue(condition.value),
  set: (value: string) => {
    condition.value = withSingleValue(condition.value, value)
  },
})

const list = computed({
  get: () => condition.value.value,
  set: (value: string[]) => {
    condition.value = { ...condition.value, value }
  },
})

const platform = computed({
  get: () => singleValue(condition.value),
  set: (value: string) => {
    condition.value = withSingleValue(condition.value, value)
  },
})

function setType(value: unknown): void {
  condition.value = changeType(condition.value, props.schema, String(value))
}

function setOperator(value: unknown): void {
  condition.value = changeOperator(condition.value, props.schema, String(value))
}

function setKey(value: string | number): void {
  condition.value = { ...condition.value, key: String(value) }
}
</script>

<template>
  <div class="flex flex-col gap-2 rounded-lg border p-3" role="group" :aria-label="interpolate(t.condition_n, { number: index + 1 })">
    <div class="flex items-start gap-2">
      <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <FormField :id="id('type')" :label="t.fields.type" v-slot="{ invalid, describedBy }" :error="errors.type">
          <Select :model-value="condition.type" @update:model-value="setType">
            <SelectTrigger :id="id('type')" class="w-full" :aria-invalid="invalid || !known" :aria-describedby="describedBy">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in schema.types" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
            </SelectContent>
          </Select>
        </FormField>

        <FormField v-if="type?.needsKey" :id="id('key')" :label="t.fields.key" v-slot="{ invalid, describedBy }" :error="errors.key">
          <Input
            :id="id('key')"
            :model-value="condition.key"
            maxlength="255"
            autocomplete="off"
            :placeholder="t.key_placeholder"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @update:model-value="setKey"
          />
        </FormField>

        <FormField :id="id('operator')" :label="t.fields.operator" v-slot="{ invalid, describedBy }" :error="errors.operator">
          <Select :model-value="condition.operator" @update:model-value="setOperator">
            <SelectTrigger :id="id('operator')" class="w-full" :aria-invalid="invalid || !known" :aria-describedby="describedBy">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in operators" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
            </SelectContent>
          </Select>
        </FormField>

        <FormField
          v-if="valueKind"
          :id="id('value')"
          :label="t.fields.value"
          :class="type?.needsKey ? 'sm:col-span-2 lg:col-span-1' : 'sm:col-span-2'"
          :hint="condition.operator === 'ne' ? t.ne_hint : valueKind === 'many-text' ? t.value_hint : undefined"
          :error="errors.value"
          v-slot="{ invalid, describedBy }"
        >
          <template v-if="valueKind === 'single-text'">
            <Input
              :id="id('value')"
              v-model="text"
              maxlength="255"
              autocomplete="off"
              :list="suggestions.length ? `${id('value')}-suggestions` : undefined"
              :placeholder="t.value_placeholder"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
            />
            <datalist v-if="suggestions.length" :id="`${id('value')}-suggestions`">
              <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
            </datalist>
          </template>

          <Select v-else-if="valueKind === 'single-platform'" v-model="platform">
            <SelectTrigger :id="id('value')" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
              <SelectValue :placeholder="t.pick_values" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in options.platforms" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
            </SelectContent>
          </Select>

          <ValueListInput
            v-else-if="valueKind === 'many-text'"
            :id="id('value')"
            v-model="list"
            :suggestions="suggestions"
            :placeholder="t.value_placeholder"
            :add-label="t.add_value"
            :remove-label="t.remove_value"
            :invalid="invalid"
            :described-by="describedBy"
          />

          <OptionsPicker
            v-else
            :id="id('value')"
            v-model="list"
            :options="optionList"
            :pick-label="t.pick_values"
            :empty-label="t.no_options"
            :missing-label="t.deleted_group"
            :remove-label="t.remove_value"
            :invalid="invalid"
            :described-by="describedBy"
          />
        </FormField>
      </div>

      <Button type="button" variant="ghost" size="icon-sm" class="mt-6 shrink-0" :aria-label="interpolate(t.remove_condition, { number: index + 1 })" @click="emit('remove')">
        <Trash2 aria-hidden="true" />
      </Button>
    </div>

    <p v-if="!known" role="alert" class="text-destructive text-sm">{{ t.unknown_condition }}</p>
  </div>
</template>
