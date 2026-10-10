<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Textarea } from '@fapost/ui/components/textarea'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@fapost/ui/components/tabs'
import { filledLocales, type MessageEntry } from '../Broadcasts/localized'

/**
 * A text in each language, one tab per language, all optional. A tab with text carries a dot, and a server error on a
 * language opens that language's tab, so it is not left hidden. `errors` are keyed by language.
 */
const props = withDefaults(
  defineProps<{
    id: string
    label: string
    languages: string[]
    errors: Record<string, string | undefined>
    rows?: number
  }>(),
  { rows: 3 },
)

const entries = defineModel<MessageEntry[]>({ required: true })

const active = ref(props.languages[0] ?? '')
const filled = computed(() => filledLocales(entries.value))

function textOf(code: string): string {
  return entries.value.find((entry) => entry.locale === code)?.text ?? ''
}

function setText(code: string, text: string): void {
  entries.value = entries.value.some((entry) => entry.locale === code)
    ? entries.value.map((entry) => (entry.locale === code ? { ...entry, text } : entry))
    : [...entries.value, { locale: code, text }]
}

watch(
  () => props.languages.filter((code) => props.errors[code] !== undefined),
  (withErrors) => {
    if (withErrors.length > 0 && !withErrors.includes(active.value)) {
      active.value = withErrors[0]
    }
  },
)
</script>

<template>
  <Tabs v-model="active" class="gap-3">
    <TabsList>
      <TabsTrigger v-for="code in languages" :key="code" :value="code" :aria-invalid="errors[code] !== undefined">
        {{ code.toUpperCase() }}
        <span v-if="filled.has(code)" class="bg-success-dot size-1.5 rounded-full" aria-hidden="true" />
      </TabsTrigger>
    </TabsList>

    <TabsContent v-for="code in languages" :key="code" :value="code" class="grid gap-1.5">
      <Textarea
        :id="`${id}-${code}`"
        :model-value="textOf(code)"
        :rows="rows"
        :aria-label="`${label} (${code.toUpperCase()})`"
        :aria-invalid="errors[code] !== undefined"
        :aria-describedby="errors[code] ? `${id}-${code}-error` : undefined"
        @update:model-value="(text) => setText(code, String(text))"
      />
      <p v-if="errors[code]" :id="`${id}-${code}-error`" role="alert" class="text-destructive text-[12.5px]">{{ errors[code] }}</p>
    </TabsContent>
  </Tabs>
</template>
