<script setup lang="ts">
import { computed, ref, watch } from "vue"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@fapost/ui/components/tabs"
import { Textarea } from "@fapost/ui/components/textarea"
import { filledLocales, setLocalizedText, tabToReveal, textOf, type LocalizedEntry, type LocalizedLanguage } from "./localized"

/**
 * A text in each language, one tab per language in the order given. A tab with text carries a dot, a required
 * language carries `requiredLabel`, and a server error on a language opens that language's tab, so it is not left
 * hidden. `errors` are keyed by language code. The field carries no text of its own; every label comes from the page.
 */
const props = withDefaults(
  defineProps<{
    id: string
    languages: LocalizedLanguage[]
    /**
     * Keyed by language code. A new object opens the first tab with an error, so pass one that changes only with the
     * form's errors (a `computed`), not one built anew on every render.
     */
    errors?: Record<string, string | undefined>
    /** Names each text area together with its language (`Message (RU)`); without it the language code alone. */
    label?: string
    /** Marks a required language's tab. */
    requiredLabel?: string
    /** The prefix of each text area's `name` (`message` gives `message.ru`); without it they have none. */
    name?: string
    rows?: number
  }>(),
  { errors: () => ({}), rows: 3 },
)

const entries = defineModel<LocalizedEntry[]>({ required: true })

const codes = computed(() => props.languages.map((language) => language.code))
const active = ref(codes.value[0] ?? "")
const filled = computed(() => filledLocales(entries.value))

function areaLabel(code: string): string {
  return props.label === undefined ? code.toUpperCase() : `${props.label} (${code.toUpperCase()})`
}

function setText(code: string, text: string): void {
  entries.value = setLocalizedText(entries.value, code, text)
}

// A server error on a language comes from a submit, and each response brings a new errors object: show the first
// language that has one. Only a new object moves the tab, so the user can still open another tab while an error
// stands, and a repeated submit with the same errors brings the error back into view.
watch(
  () => props.errors,
  () => {
    const tab = tabToReveal(props.errors, codes.value, active.value)

    if (tab !== null) {
      active.value = tab
    }
  },
)
</script>

<template>
  <Tabs v-model="active" class="gap-3">
    <TabsList>
      <TabsTrigger v-for="language in languages" :key="language.code" :value="language.code" :aria-invalid="errors[language.code] !== undefined">
        {{ language.code.toUpperCase() }}
        <span v-if="filled.has(language.code)" class="bg-success-dot size-1.5 rounded-full" aria-hidden="true" />
        <span v-if="language.required && requiredLabel" class="text-muted-foreground text-xs font-normal">{{ requiredLabel }}</span>
      </TabsTrigger>
    </TabsList>

    <TabsContent v-for="language in languages" :key="language.code" :value="language.code" class="grid gap-1.5">
      <Textarea
        :id="`${id}-${language.code}`"
        :model-value="textOf(entries, language.code)"
        :name="name === undefined ? undefined : `${name}.${language.code}`"
        :rows="rows"
        :aria-label="areaLabel(language.code)"
        :aria-invalid="errors[language.code] !== undefined"
        :aria-describedby="errors[language.code] ? `${id}-${language.code}-error` : undefined"
        @update:model-value="(text) => setText(language.code, String(text))"
      />
      <p v-if="errors[language.code]" :id="`${id}-${language.code}-error`" role="alert" class="text-destructive text-[12.5px]">
        {{ errors[language.code] }}
      </p>
    </TabsContent>
  </Tabs>
</template>
