<script setup lang="ts">
import { computed } from 'vue'
import { Monitor, Moon, Sun } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@fapost/ui/components/dropdown-menu'
import { isThemePreference } from './theme'
import { useTheme } from './useTheme'

const props = defineProps<{ labels: { label: string; light: string; dark: string; system: string } }>()

const { preference, setPreference } = useTheme()

const icon = computed(() => (preference.value === 'dark' ? Moon : preference.value === 'light' ? Sun : Monitor))

function choose(value: unknown): void {
  if (isThemePreference(value)) {
    setPreference(value)
  }
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="icon-sm" :aria-label="props.labels.label">
        <component :is="icon" aria-hidden="true" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuRadioGroup :model-value="preference" @update:model-value="choose">
        <DropdownMenuRadioItem value="light">{{ props.labels.light }}</DropdownMenuRadioItem>
        <DropdownMenuRadioItem value="dark">{{ props.labels.dark }}</DropdownMenuRadioItem>
        <DropdownMenuRadioItem value="system">{{ props.labels.system }}</DropdownMenuRadioItem>
      </DropdownMenuRadioGroup>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
