<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Textarea } from '@fapost/ui/components/textarea'
import { interpolate } from '@console/shell'
import { DEFAULT_OPTION, fromSelect, groupTitle, initialValues, textsOf, toSelect } from './form'
import type { PlatformSettingItem, PlatformSettingsGroup, PlatformSettingsPageProps, SettingValue } from './types'

/**
 * The platform settings of a single-tenant deployment: every setting with its default and whether a value is stored,
 * saved as a whole. An empty field, or "Use default", sends null and the server removes the stored value.
 */
const props = defineProps<{
  groups: PlatformSettingsGroup[]
  urls: { submit: string }
}>()

const page = usePage<PlatformSettingsPageProps>()
const t = computed(() => page.props.translations.console.platform_settings)
const common = computed(() => page.props.translations.console.form)

const form = useForm<Record<string, SettingValue | null>>(initialValues(props.groups))

function defaultText(setting: PlatformSettingItem): string {
  const value = typeof setting.default === 'boolean' ? (setting.default ? t.value.on : t.value.off) : String(setting.default)

  return interpolate(t.value.default, { value: setting.unit ? `${value} ${setting.unit}` : value })
}

function rangeText(setting: PlatformSettingItem): string | null {
  if (setting.type !== 'integer') {
    return null
  }

  if (setting.min !== null && setting.max !== null) {
    return interpolate(t.value.range, { min: setting.min, max: setting.max })
  }

  if (setting.min !== null) {
    return interpolate(t.value.min, { min: setting.min })
  }

  return setting.max !== null ? interpolate(t.value.max, { max: setting.max }) : null
}

function hint(setting: PlatformSettingItem): string {
  return [textsOf(setting, t.value).description, rangeText(setting), defaultText(setting)].filter(Boolean).join(' ')
}

function save(): void {
  form.put(props.urls.submit, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div>
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground mt-1 text-sm">{{ t.description }}</p>
    </div>

    <form class="flex flex-col gap-5" novalidate @submit.prevent="save">
      <FormSection v-for="group in groups" :key="group.name" :title="groupTitle(group.name, t)">
        <FormField
          v-for="setting in group.settings"
          :id="setting.field"
          :key="setting.key"
          :label="textsOf(setting, t).label"
          :hint="hint(setting)"
          :error="form.errors[setting.field]"
          v-slot="{ invalid, describedBy }"
        >
          <div class="flex flex-wrap items-center gap-2">
            <Select
              v-if="setting.type === 'boolean' || setting.type === 'choice'"
              :model-value="toSelect(form[setting.field])"
              @update:model-value="form[setting.field] = fromSelect(setting, String($event))"
            >
              <SelectTrigger :id="setting.field" class="w-56" :aria-invalid="invalid" :aria-describedby="describedBy">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="DEFAULT_OPTION">{{ t.default_option }}</SelectItem>
                <template v-if="setting.type === 'boolean'">
                  <SelectItem value="true">{{ t.on }}</SelectItem>
                  <SelectItem value="false">{{ t.off }}</SelectItem>
                </template>
                <template v-else>
                  <SelectItem v-for="choice in setting.choices" :key="choice" :value="choice">{{ choice }}</SelectItem>
                </template>
              </SelectContent>
            </Select>

            <Textarea
              v-else-if="setting.type === 'text'"
              :id="setting.field"
              :model-value="form[setting.field] === null ? '' : String(form[setting.field])"
              :rows="2"
              :placeholder="String(setting.default)"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              @update:model-value="form[setting.field] = String($event) === '' ? null : String($event)"
            />

            <Input
              v-else
              :id="setting.field"
              :model-value="form[setting.field] === null ? '' : String(form[setting.field])"
              type="number"
              inputmode="numeric"
              class="w-40"
              :min="setting.min ?? undefined"
              :max="setting.max ?? undefined"
              :placeholder="String(setting.default)"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              @update:model-value="form[setting.field] = String($event) === '' ? null : String($event)"
            />

            <span v-if="setting.unit && setting.type === 'integer'" class="text-muted-foreground text-sm">{{ setting.unit }}</span>
            <Badge :variant="setting.stored ? 'default' : 'secondary'">{{ setting.stored ? t.stored : t.not_stored }}</Badge>
            <Button v-if="form[setting.field] !== null" type="button" variant="ghost" size="sm" @click="form[setting.field] = null">{{ t.use_default }}</Button>
          </div>
        </FormField>
      </FormSection>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      </div>
    </form>
  </div>
</template>
