<script setup lang="ts">
import { ref } from "vue"
import { X } from "@lucide/vue"
import { Badge } from "@fapost/ui/components/badge"
import { Button } from "@fapost/ui/components/button"
import { Input } from "@fapost/ui/components/input"
import { addValues, removeValue } from "./values"

/**
 * A list of free-text values as chips: Enter or a comma adds what was typed, the cross on a chip removes it.
 * Suggestions are the browser's own `datalist`. What is typed and not yet added is added when the field loses focus,
 * so it still counts when the form is saved; `commit()` is exposed for a form that adds it itself before sending.
 * `addLabel` adds a button next to the field for those who do not know the keys. The input carries no text of its
 * own; every label comes from the page.
 */
const props = withDefaults(
  defineProps<{
    id: string
    suggestions?: string[]
    placeholder?: string
    /** The text of an add button next to the field; without it there is none. */
    addLabel?: string
    /** The accessible name of a chip's remove button. */
    removeLabel: (value: string) => string
    maxlength?: number
    invalid?: boolean
    describedBy?: string
  }>(),
  { suggestions: () => [], maxlength: 255 },
)

const values = defineModel<string[]>({ required: true })
const draft = ref("")

function commit(): void {
  if (draft.value.trim() !== "") {
    values.value = addValues(values.value, draft.value)
  }

  draft.value = ""
}

defineExpose({ commit })

function remove(value: string): void {
  values.value = removeValue(values.value, value)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === "Enter" || event.key === ",") {
    event.preventDefault()
    commit()
  }
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <div v-if="values.length > 0" class="flex flex-wrap gap-1.5">
      <Badge v-for="value in values" :key="value" variant="neutral" class="gap-1 pr-1">
        <span class="max-w-48 truncate">{{ value }}</span>
        <button type="button" class="hover:bg-foreground/10 rounded-full p-0.5" :aria-label="props.removeLabel(value)" @click="remove(value)">
          <X class="size-3" aria-hidden="true" />
        </button>
      </Badge>
    </div>

    <div class="flex gap-2">
      <Input
        :id="id"
        v-model="draft"
        :maxlength="maxlength"
        autocomplete="off"
        :list="suggestions.length > 0 ? `${id}-suggestions` : undefined"
        :placeholder="placeholder"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
        @keydown="onKeydown"
        @blur="commit"
      />
      <Button v-if="addLabel" type="button" variant="outline" class="shrink-0" @click="commit">{{ addLabel }}</Button>
    </div>
    <datalist v-if="suggestions.length > 0" :id="`${id}-suggestions`">
      <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
    </datalist>
  </div>
</template>
