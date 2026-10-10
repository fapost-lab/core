<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { ChevronDown, X } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { Input } from '@fapost/ui/components/input'
import type { SelectOption } from './types'

/**
 * Chooses some of a long list (the countries): a search field on top of the menu filters it, the menu stays open while
 * ticking, and the chosen ones show as removable badges under the button. Only offered values can be chosen; the
 * server holds to that as well.
 */
const props = defineProps<{
  id: string
  options: SelectOption[]
  placeholder: string
  searchLabel: string
  emptyLabel: string
  selectedLabel: (count: number) => string
  removeLabel: (label: string) => string
  invalid?: boolean
  describedBy?: string
}>()

const selected = defineModel<string[]>({ required: true })

const query = ref('')
const search = ref<InstanceType<typeof Input> | null>(null)

const labels = computed(() => new Map(props.options.map((option) => [option.value, option.label])))
const label = computed(() => (selected.value.length === 0 ? props.placeholder : props.selectedLabel(selected.value.length)))
const visible = computed(() => {
  const needle = query.value.trim().toLowerCase()

  return needle === '' ? props.options : props.options.filter((option) => option.label.toLowerCase().includes(needle) || option.value.toLowerCase() === needle)
})

function toggle(value: string, on: boolean): void {
  selected.value = on ? [...selected.value, value] : selected.value.filter((chosen) => chosen !== value)
}

function onOpen(open: boolean): void {
  if (!open) {
    query.value = ''

    return
  }

  void nextTick(() => (search.value?.$el as HTMLInputElement | undefined)?.focus())
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <DropdownMenu @update:open="onOpen">
      <DropdownMenuTrigger as-child>
        <Button :id="id" type="button" variant="outline" class="w-full justify-between font-normal" :aria-invalid="invalid" :aria-describedby="describedBy">
          <span class="truncate">{{ label }}</span>
          <ChevronDown aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" class="max-h-80 w-(--reka-dropdown-menu-trigger-width)">
        <div class="p-1">
          <!-- The menu's own typeahead would take the keys, so they stop at the field. -->
          <Input ref="search" v-model="query" type="search" :placeholder="searchLabel" :aria-label="searchLabel" autocomplete="off" @keydown.stop />
        </div>
        <p v-if="visible.length === 0" class="text-muted-foreground px-2 py-1.5 text-sm">{{ emptyLabel }}</p>
        <DropdownMenuCheckboxItem
          v-for="option in visible"
          :key="option.value"
          :model-value="selected.includes(option.value)"
          @update:model-value="(on) => toggle(option.value, on === true)"
          @select.prevent
        >
          {{ option.label }}
        </DropdownMenuCheckboxItem>
      </DropdownMenuContent>
    </DropdownMenu>

    <div v-if="selected.length > 0" class="flex flex-wrap gap-1.5">
      <Badge v-for="value in selected" :key="value" variant="neutral" class="gap-1 pr-1">
        {{ labels.get(value) ?? value }}
        <button type="button" class="hover:text-foreground rounded-sm" :aria-label="removeLabel(labels.get(value) ?? value)" @click="toggle(value, false)">
          <X class="size-3" aria-hidden="true" />
        </button>
      </Badge>
    </div>
  </div>
</template>
