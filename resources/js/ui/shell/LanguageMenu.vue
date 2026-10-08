<script setup lang="ts">
import { Globe } from '@lucide/vue'
import { router } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@fapost/ui/components/dropdown-menu'

const props = defineProps<{
  url: string
  current: string
  locales: string[]
  /** Display name per locale, plus `label` for the button. */
  labels: Record<string, string>
}>()

/** The answer is a full page visit: every screen's translations change with the language. */
function choose(value: unknown): void {
  if (typeof value === 'string' && value !== props.current) {
    router.post(props.url, { locale: value })
  }
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="sm" :aria-label="props.labels.label">
        <Globe aria-hidden="true" />
        <span class="uppercase">{{ props.current }}</span>
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuRadioGroup :model-value="props.current" @update:model-value="choose">
        <DropdownMenuRadioItem v-for="locale in props.locales" :key="locale" :value="locale">
          {{ props.labels[locale] ?? locale }}
        </DropdownMenuRadioItem>
      </DropdownMenuRadioGroup>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
