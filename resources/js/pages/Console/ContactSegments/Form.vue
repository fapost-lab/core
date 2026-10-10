<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Plus } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import ConditionRow from './ConditionRow.vue'
import { errorsOfCondition, newCondition } from './rules'
import type { Condition, ContactSegmentsPageProps, SegmentFields, SegmentOptions, SegmentSchema } from './types'

/** The fields of a segment, shared by the create and the edit screen. The server validates; its errors show under the fields and rows. */
const props = defineProps<{
  initial: SegmentFields
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
  schema: SegmentSchema
  options: SegmentOptions
}>()

const page = usePage<ContactSegmentsPageProps>()
const t = computed(() => page.props.translations.console.contact_segments)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
  match: props.initial.match,
  conditions: props.initial.conditions.map((condition) => ({ ...condition, value: [...condition.value] })),
})

// Rows have no id of their own; a key per row keeps a row's inputs with it when another row is removed.
let nextKey = 0
const rowKeys = ref<number[]>(form.conditions.map(() => nextKey++))

function addCondition(): void {
  form.conditions.push(newCondition(props.schema))
  rowKeys.value.push(nextKey++)
}

function removeCondition(index: number): void {
  form.conditions.splice(index, 1)
  rowKeys.value.splice(index, 1)
}

function setCondition(index: number, condition: Condition): void {
  form.conditions[index] = condition
}

function submit(): void {
  form.submit(props.method, props.submitUrl, { preserveScroll: true })
}
</script>

<template>
  <form class="flex max-w-4xl flex-col gap-5" novalidate @submit.prevent="submit">
    <div class="grid gap-5 sm:grid-cols-2">
      <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
        <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField id="match" :label="t.fields.match" :error="form.errors.match" v-slot="{ invalid, describedBy }">
        <Select v-model="form.match">
          <SelectTrigger id="match" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="option in schema.match" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
          </SelectContent>
        </Select>
      </FormField>
    </div>

    <fieldset class="flex flex-col gap-3">
      <legend class="mb-1 text-sm leading-none font-medium">{{ t.fields.conditions }}</legend>

      <p v-if="form.conditions.length === 0" class="text-muted-foreground text-sm">{{ t.no_conditions_hint }}</p>

      <ConditionRow
        v-for="(condition, index) in form.conditions"
        :key="rowKeys[index]"
        :model-value="condition"
        :index="index"
        :schema="schema"
        :options="options"
        :errors="errorsOfCondition(form.errors, index)"
        @update:model-value="(value: Condition) => setCondition(index, value)"
        @remove="removeCondition(index)"
      />

      <p v-if="form.errors.conditions" role="alert" class="text-destructive text-sm">{{ form.errors.conditions }}</p>

      <Button type="button" variant="outline" class="w-fit" @click="addCondition">
        <Plus aria-hidden="true" />
        {{ t.add_condition }}
      </Button>
    </fieldset>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
