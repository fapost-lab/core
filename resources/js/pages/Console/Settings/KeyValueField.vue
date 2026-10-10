<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Plus, Trash2 } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Input } from '@fapost/ui/components/input'
import { interpolate } from '@fapost/ui/shell'
import type { SettingRow, SettingsPageProps } from './types'

/**
 * Free-form settings as rows of key and value, in the order they were stored. Errors arrive keyed by the row's
 * position (`settings.2.key`).
 */
const props = defineProps<{
  errors: Record<string, string | undefined>
}>()

const rows = defineModel<SettingRow[]>({ required: true })

const page = usePage<SettingsPageProps>()
const t = computed(() => page.props.translations.console.settings)

function update(index: number, patch: Partial<SettingRow>): void {
  rows.value = rows.value.map((row, at) => (at === index ? { ...row, ...patch } : row))
}

function errorOf(index: number, field: 'key' | 'value'): string | undefined {
  return props.errors[`settings.${index}.${field}`]
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <p v-if="rows.length === 0" class="text-muted-foreground text-sm">{{ t.fields.settings_empty }}</p>

    <div v-if="rows.length > 0" class="text-muted-foreground grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_2.5rem] gap-2 text-[13px]" aria-hidden="true">
      <span>{{ t.fields.settings_key }}</span>
      <span>{{ t.fields.settings_value }}</span>
    </div>

    <div v-for="(row, index) in rows" :key="index" class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_2.5rem] items-start gap-2">
      <div class="grid gap-1">
        <Input
          :id="`setting-${index}-key`"
          :model-value="row.key"
          maxlength="255"
          autocomplete="off"
          :aria-label="t.fields.settings_key"
          :aria-invalid="errorOf(index, 'key') !== undefined"
          :aria-describedby="errorOf(index, 'key') ? `setting-${index}-key-error` : undefined"
          @update:model-value="(value) => update(index, { key: String(value) })"
        />
        <p v-if="errorOf(index, 'key')" :id="`setting-${index}-key-error`" role="alert" class="text-destructive text-[12.5px]">{{ errorOf(index, 'key') }}</p>
      </div>
      <div class="grid gap-1">
        <Input
          :id="`setting-${index}-value`"
          :model-value="row.value"
          autocomplete="off"
          :aria-label="t.fields.settings_value"
          :aria-invalid="errorOf(index, 'value') !== undefined"
          :aria-describedby="errorOf(index, 'value') ? `setting-${index}-value-error` : undefined"
          @update:model-value="(value) => update(index, { value: String(value) })"
        />
        <p v-if="errorOf(index, 'value')" :id="`setting-${index}-value-error`" role="alert" class="text-destructive text-[12.5px]">{{ errorOf(index, 'value') }}</p>
      </div>
      <Button type="button" variant="ghost" size="icon" :aria-label="interpolate(t.fields.settings_remove, { key: row.key || '—' })" @click="rows = rows.filter((_, at) => at !== index)">
        <Trash2 aria-hidden="true" />
      </Button>
    </div>

    <div>
      <Button type="button" variant="outline" @click="rows = [...rows, { key: '', value: '' }]">
        <Plus aria-hidden="true" />
        {{ t.fields.settings_add }}
      </Button>
    </div>
  </div>
</template>
