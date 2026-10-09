<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Plus, Trash2 } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Input } from '@fapost/ui/components/input'
import type { ChannelsPageProps, ConfigEntry } from './types'

/** Free settings of a channel: pairs of a key and a value. `errors` are the form's, keyed `config_entries.N.key`. */
defineProps<{
  idPrefix: string
  errors: Record<string, string | undefined>
}>()

const model = defineModel<ConfigEntry[]>({ required: true })

const t = computed(() => usePage<ChannelsPageProps>().props.translations.console.channels.fields)

function add(): void {
  model.value = [...model.value, { key: '', value: '' }]
}

function remove(index: number): void {
  model.value = model.value.filter((_, position) => position !== index)
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div v-for="(entry, index) in model" :key="index" class="flex flex-col gap-1">
      <div class="flex items-center gap-2">
        <Input
          :id="`${idPrefix}-${index}-key`"
          v-model="entry.key"
          :aria-label="t.config_key"
          :placeholder="t.config_key"
          maxlength="255"
          autocomplete="off"
          :aria-invalid="!!errors[`config_entries.${index}.key`]"
        />
        <Input
          :id="`${idPrefix}-${index}-value`"
          v-model="entry.value"
          :aria-label="t.config_value"
          :placeholder="t.config_value"
          autocomplete="off"
          :aria-invalid="!!errors[`config_entries.${index}.value`]"
        />
        <Button type="button" variant="ghost" size="icon" class="shrink-0" :aria-label="t.config_remove" @click="remove(index)">
          <Trash2 aria-hidden="true" />
        </Button>
      </div>
      <p v-if="errors[`config_entries.${index}.key`]" role="alert" class="text-destructive text-sm">{{ errors[`config_entries.${index}.key`] }}</p>
      <p v-if="errors[`config_entries.${index}.value`]" role="alert" class="text-destructive text-sm">{{ errors[`config_entries.${index}.value`] }}</p>
    </div>

    <div>
      <Button type="button" variant="outline" size="sm" @click="add">
        <Plus aria-hidden="true" />
        {{ t.config_add }}
      </Button>
    </div>
  </div>
</template>
