<script setup lang="ts">
import { computed } from 'vue'
import { ChevronDown, X } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { interpolate } from '@fapost/ui/shell'

/**
 * Picks several of a fixed list. The chosen ones show as chips; a chosen value the list no longer has (a deleted group)
 * shows as a danger chip with `missingLabel`, and can still be removed. Built from kit components only.
 */
const props = defineProps<{
  id: string
  options: { value: string; label: string }[]
  pickLabel: string
  emptyLabel: string
  missingLabel: string
  /** The accessible name of a chip's remove button, with `:value` for the chip's text. */
  removeLabel: string
  invalid?: boolean
  describedBy?: string
}>()

const selected = defineModel<string[]>({ required: true })

const chips = computed(() =>
  selected.value.map((value) => {
    const option = props.options.find((candidate) => candidate.value === value)

    return { value, label: option?.label ?? props.missingLabel, missing: option === undefined }
  }),
)

function toggle(value: string, on: boolean): void {
  selected.value = on ? [...selected.value.filter((existing) => existing !== value), value] : selected.value.filter((existing) => existing !== value)
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <div v-if="chips.length" class="flex flex-wrap gap-1.5">
      <Badge v-for="chip in chips" :key="chip.value" :variant="chip.missing ? 'destructive' : 'secondary'" class="gap-1 pr-1">
        <span class="max-w-48 truncate">{{ chip.label }}</span>
        <button
          type="button"
          class="hover:bg-foreground/10 rounded-full p-0.5"
          :aria-label="interpolate(removeLabel, { value: chip.label })"
          @click="toggle(chip.value, false)"
        >
          <X class="size-3" aria-hidden="true" />
        </button>
      </Badge>
    </div>

    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button :id="id" type="button" variant="outline" class="justify-between" :aria-invalid="invalid" :aria-describedby="describedBy">
          {{ pickLabel }}
          <ChevronDown aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" class="max-h-72 min-w-56 overflow-y-auto">
        <p v-if="options.length === 0" class="text-muted-foreground px-2 py-1.5 text-sm">{{ emptyLabel }}</p>
        <DropdownMenuCheckboxItem
          v-for="option in options"
          :key="option.value"
          :model-value="selected.includes(option.value)"
          @select.prevent
          @update:model-value="(on: boolean) => toggle(option.value, on)"
        >
          {{ option.label }}
        </DropdownMenuCheckboxItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
