<script setup lang="ts">
import { ref } from 'vue'
import { X } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Input } from '@fapost/ui/components/input'
import { interpolate } from '@fapost/ui/shell'
import { addTags, removeTag } from '../Contacts/tags'

/**
 * A list of free-text values as chips: Enter or a comma adds what was typed, the cross removes a chip. Suggestions are
 * the browser's own `datalist`. Built from kit components only; it moves into the kit when a second screen needs it.
 */
const props = defineProps<{
  id: string
  suggestions: string[]
  placeholder: string
  addLabel: string
  /** The accessible name of a chip's remove button, with `:value` for the chip's text. */
  removeLabel: string
  invalid?: boolean
  describedBy?: string
}>()

const values = defineModel<string[]>({ required: true })
const draft = ref('')

// What is typed and not yet added still counts when the form is saved.
function commit(): void {
  values.value = addTags(values.value, draft.value)
  draft.value = ''
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()
    commit()
  }
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <div v-if="values.length" class="flex flex-wrap gap-1.5">
      <Badge v-for="value in values" :key="value" variant="secondary" class="gap-1 pr-1">
        <span class="max-w-48 truncate">{{ value }}</span>
        <button
          type="button"
          class="hover:bg-foreground/10 rounded-full p-0.5"
          :aria-label="interpolate(props.removeLabel, { value })"
          @click="values = removeTag(values, value)"
        >
          <X class="size-3" aria-hidden="true" />
        </button>
      </Badge>
    </div>

    <div class="flex gap-2">
      <Input
        :id="id"
        v-model="draft"
        maxlength="255"
        autocomplete="off"
        :list="`${id}-suggestions`"
        :placeholder="placeholder"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
        @keydown="onKeydown"
        @blur="commit"
      />
      <Button type="button" variant="outline" class="shrink-0" @click="commit">{{ addLabel }}</Button>
    </div>
    <datalist :id="`${id}-suggestions`">
      <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
    </datalist>
  </div>
</template>
