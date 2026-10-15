<script setup lang="ts">
import { computed, nextTick, ref } from "vue"
import { ChevronDown, X } from "@lucide/vue"
import { Badge } from "@fapost/ui/components/badge"
import { Button } from "@fapost/ui/components/button"
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from "@fapost/ui/components/dropdown-menu"
import { Input } from "@fapost/ui/components/input"
import { chipsOf, filterOptions, normalizeOptions, toggleValue, type PickerOption } from "./options"

/**
 * Chooses some of a fixed list. The menu stays open while ticking, and the chosen values show as chips under the
 * button, in the order they were chosen. Only offered values can be ticked; the server holds to that as well.
 *
 * Optional parts switch on with their labels: `searchLabel` puts a search field on top of the menu (long lists),
 * `removeLabel` gives each chip a remove button, `selectedLabel` names the count on the button once something is
 * chosen. A chosen value the list no longer has shows as a danger chip, named `missingLabel` or by its raw value.
 * The picker carries no text of its own; every label comes from the page.
 */
const props = defineProps<{
  id: string
  /** Plain strings are their own labels. */
  options: ReadonlyArray<string | PickerOption>
  /** The button's text while nothing is chosen, and always when there is no `selectedLabel`. */
  placeholder: string
  /** The button's text once something is chosen. */
  selectedLabel?: (count: number) => string
  /** Shown in the menu when there is nothing to choose, or nothing matches the search. */
  emptyLabel: string
  /** The search field's placeholder and accessible name; without it there is no search. */
  searchLabel?: string
  /** The accessible name of a chip's remove button; without it the chips cannot be removed. */
  removeLabel?: (label: string) => string
  /** What a chosen value the list lacks is called; without it the raw value shows. */
  missingLabel?: string
  invalid?: boolean
  describedBy?: string
}>()

const selected = defineModel<string[]>({ required: true })

const query = ref("")
const search = ref<InstanceType<typeof Input> | null>(null)

const all = computed(() => normalizeOptions(props.options))
const visible = computed(() => (props.searchLabel === undefined ? all.value : filterOptions(all.value, query.value)))
const chips = computed(() => chipsOf(selected.value, all.value, props.missingLabel))
const buttonLabel = computed(() =>
  selected.value.length === 0 || props.selectedLabel === undefined ? props.placeholder : props.selectedLabel(selected.value.length),
)

function toggle(value: string, on: boolean): void {
  selected.value = toggleValue(selected.value, value, on)
}

function onOpen(open: boolean): void {
  if (!open) {
    query.value = ""

    return
  }

  if (props.searchLabel !== undefined) {
    void nextTick(() => (search.value?.$el as HTMLInputElement | undefined)?.focus())
  }
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <DropdownMenu @update:open="onOpen">
      <DropdownMenuTrigger as-child>
        <Button :id="id" type="button" variant="outline" class="w-full justify-between font-normal" :aria-invalid="invalid" :aria-describedby="describedBy">
          <span class="truncate">{{ buttonLabel }}</span>
          <ChevronDown aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" class="max-h-80 w-(--reka-dropdown-menu-trigger-width) overflow-y-auto">
        <div v-if="searchLabel !== undefined" class="p-1">
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

    <div v-if="chips.length > 0" class="flex flex-wrap gap-1.5">
      <Badge v-for="chip in chips" :key="chip.value" :variant="chip.missing ? 'danger' : 'neutral'" :class="removeLabel ? 'gap-1 pr-1' : undefined">
        <span class="max-w-48 truncate">{{ chip.label }}</span>
        <button
          v-if="removeLabel"
          type="button"
          class="hover:bg-foreground/10 rounded-full p-0.5"
          :aria-label="removeLabel(chip.label)"
          @click="toggle(chip.value, false)"
        >
          <X class="size-3" aria-hidden="true" />
        </button>
      </Badge>
    </div>
  </div>
</template>
