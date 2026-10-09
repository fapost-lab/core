<script setup lang="ts">
import { ArrowLeft, Check, ChevronsUpDown } from '@lucide/vue'
import { Link } from '@inertiajs/vue3'
import { Avatar, AvatarFallback } from '@fapost/ui/components/avatar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@fapost/ui/components/dropdown-menu'
import { initials } from './nav'
import type { AssistantSwitcherData } from './types'

defineProps<{
  data: AssistantSwitcherData
  label: string
  backLabel: string
}>()
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        class="bg-card hover:bg-accent/40 focus-visible:ring-ring/30 flex min-h-12 w-full items-center gap-2.5 rounded-lg border px-2.5 py-2 text-left transition-colors outline-none focus-visible:ring-3"
      >
        <Avatar class="size-[30px] rounded-lg">
          <AvatarFallback class="bg-accent text-accent-foreground rounded-lg text-xs font-semibold">
            {{ initials(data.current.name) }}
          </AvatarFallback>
        </Avatar>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-[13.5px] leading-tight font-semibold">{{ data.current.name }}</span>
          <span class="text-faint-foreground block text-xs leading-tight">{{ label }}</span>
        </span>
        <ChevronsUpDown class="text-faint-foreground size-4 shrink-0" aria-hidden="true" />
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="start" class="w-64">
      <DropdownMenuLabel class="text-muted-foreground text-xs">{{ label }}</DropdownMenuLabel>
      <template v-for="assistant in data.items" :key="assistant.id">
        <DropdownMenuItem v-if="assistant.external" as-child>
          <a :href="assistant.href">
            <span class="flex-1 truncate">{{ assistant.name }}</span>
            <Check v-if="assistant.id === data.current.id" class="size-4" aria-hidden="true" />
          </a>
        </DropdownMenuItem>
        <DropdownMenuItem v-else as-child>
          <Link :href="assistant.href">
            <span class="flex-1 truncate">{{ assistant.name }}</span>
            <Check v-if="assistant.id === data.current.id" class="size-4" aria-hidden="true" />
          </Link>
        </DropdownMenuItem>
      </template>
      <DropdownMenuSeparator />
      <DropdownMenuItem as-child>
        <a v-if="data.back.external" :href="data.back.href">
          <ArrowLeft aria-hidden="true" />
          {{ backLabel }}
        </a>
        <Link v-else :href="data.back.href">
          <ArrowLeft aria-hidden="true" />
          {{ backLabel }}
        </Link>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
