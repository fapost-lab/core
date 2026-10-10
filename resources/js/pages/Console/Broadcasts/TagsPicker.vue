<script setup lang="ts">
import { computed } from 'vue'
import { ChevronDown } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'

/**
 * Chooses some of the tags contacts carry. The menu stays open while ticking, the chosen tags show as badges under
 * the button. Only the offered tags can be chosen; the server holds to that as well.
 */
const props = defineProps<{
  id: string
  options: string[]
  placeholder: string
  emptyLabel: string
  selectedLabel: (count: number) => string
  invalid?: boolean
  describedBy?: string
}>()

const selected = defineModel<string[]>({ required: true })

const label = computed(() => (selected.value.length === 0 ? props.placeholder : props.selectedLabel(selected.value.length)))

function toggle(tag: string, on: boolean): void {
  selected.value = on ? [...selected.value, tag] : selected.value.filter((chosen) => chosen !== tag)
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button :id="id" type="button" variant="outline" class="w-full justify-between font-normal" :aria-invalid="invalid" :aria-describedby="describedBy">
          <span class="truncate">{{ label }}</span>
          <ChevronDown aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" class="max-h-72 w-(--reka-dropdown-menu-trigger-width) overflow-y-auto">
        <p v-if="options.length === 0" class="text-muted-foreground px-2 py-1.5 text-sm">{{ emptyLabel }}</p>
        <DropdownMenuCheckboxItem
          v-for="tag in options"
          :key="tag"
          :model-value="selected.includes(tag)"
          @update:model-value="(on) => toggle(tag, on === true)"
          @select.prevent
        >
          {{ tag }}
        </DropdownMenuCheckboxItem>
      </DropdownMenuContent>
    </DropdownMenu>

    <div v-if="selected.length > 0" class="flex flex-wrap gap-1.5">
      <Badge v-for="tag in selected" :key="tag" variant="neutral">{{ tag }}</Badge>
    </div>
  </div>
</template>
