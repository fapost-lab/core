<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Checkbox } from '@fapost/ui/components/checkbox'
import { Label } from '@fapost/ui/components/label'
import type { ChannelsPageProps, SelectOption } from './types'

/** The Telegram update types a webhook receives, as a grid of checkboxes with a way to pick or clear them all. */
const props = defineProps<{
  idPrefix: string
  options: SelectOption[]
}>()

const model = defineModel<string[]>({ required: true })

const t = computed(() => usePage<ChannelsPageProps>().props.translations.console.channels.fields)

function toggle(value: string, checked: boolean | 'indeterminate'): void {
  const without = model.value.filter((selected) => selected !== value)

  // Keep the options' own order, so the saved list does not depend on the order the boxes were ticked in.
  model.value = checked === true ? props.options.map((option) => option.value).filter((option) => option === value || without.includes(option)) : without
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex items-center gap-4 text-sm">
      <Button type="button" variant="link" class="h-auto p-0" @click="model = options.map((option) => option.value)">{{ t.select_all }}</Button>
      <Button type="button" variant="link" class="text-muted-foreground h-auto p-0" @click="model = []">{{ t.clear_all }}</Button>
    </div>
    <div class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="option in options" :key="option.value" class="flex items-center gap-2">
        <Checkbox :id="`${idPrefix}-${option.value}`" :model-value="model.includes(option.value)" @update:model-value="(checked) => toggle(option.value, checked)" />
        <Label :for="`${idPrefix}-${option.value}`" class="font-normal">{{ option.label }}</Label>
      </div>
    </div>
  </div>
</template>
