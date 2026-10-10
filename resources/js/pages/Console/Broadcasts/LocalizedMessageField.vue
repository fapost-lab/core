<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Textarea } from '@fapost/ui/components/textarea'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@fapost/ui/components/tabs'
import { filledLocales, localesWithErrors, type MessageEntry } from './localized'
import type { BroadcastLanguage } from './types'

/**
 * The text of a message in each language, one tab per language, the base language first and marked as required. A
 * tab with text carries a dot, and a server error on a language opens that language's tab, so it is not left hidden.
 */
const props = defineProps<{
  id: string
  languages: BroadcastLanguage[]
  errors: Record<string, string | undefined>
  requiredLabel: string
}>()

const entries = defineModel<MessageEntry[]>({ required: true })

const codes = computed(() => props.languages.map((language) => language.code))
const active = ref(codes.value[0] ?? '')
const filled = computed(() => filledLocales(entries.value))

function entryOf(code: string): MessageEntry | undefined {
  return entries.value.find((entry) => entry.locale === code)
}

function setText(code: string, text: string): void {
  entries.value = entries.value.map((entry) => (entry.locale === code ? { ...entry, text } : entry))
}

// A server error on a language comes from a submit; show the first language that has one.
watch(
  () => localesWithErrors(props.errors, codes.value),
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
      <TabsTrigger v-for="language in languages" :key="language.code" :value="language.code" :aria-invalid="errors[`message.${language.code}`] !== undefined">
        {{ language.code.toUpperCase() }}
        <span v-if="filled.has(language.code)" class="bg-success-dot size-1.5 rounded-full" aria-hidden="true" />
        <span v-if="language.isBase" class="text-muted-foreground text-xs font-normal">{{ requiredLabel }}</span>
      </TabsTrigger>
    </TabsList>

    <TabsContent v-for="language in languages" :key="language.code" :value="language.code" class="grid gap-1.5">
      <Textarea
        :id="`${id}-${language.code}`"
        :model-value="entryOf(language.code)?.text ?? ''"
        :name="`message.${language.code}`"
        rows="6"
        :aria-label="language.code.toUpperCase()"
        :aria-invalid="errors[`message.${language.code}`] !== undefined"
        :aria-describedby="errors[`message.${language.code}`] ? `${id}-${language.code}-error` : undefined"
        @update:model-value="(text) => setText(language.code, String(text))"
      />
      <p v-if="errors[`message.${language.code}`]" :id="`${id}-${language.code}-error`" role="alert" class="text-destructive text-[12.5px]">
        {{ errors[`message.${language.code}`] }}
      </p>
    </TabsContent>
  </Tabs>
</template>
