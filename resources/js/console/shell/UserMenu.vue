<script setup lang="ts">
import { LogOut } from '@lucide/vue'
import { router } from '@inertiajs/vue3'
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
import type { ShellUser } from './types'

const props = defineProps<{
  user: ShellUser
  logoutUrl: string
  labels: { label: string; sign_out: string }
}>()

function signOut(): void {
  router.post(props.logoutUrl)
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        class="focus-visible:ring-ring/50 rounded-full outline-none focus-visible:ring-3"
        :aria-label="props.labels.label"
      >
        <Avatar class="size-8">
          <AvatarFallback class="bg-accent text-accent-foreground text-xs font-medium">
            {{ initials(props.user.name) }}
          </AvatarFallback>
        </Avatar>
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-56">
      <DropdownMenuLabel class="font-normal">
        <span class="block truncate text-sm font-medium">{{ props.user.name }}</span>
        <span class="text-muted-foreground block truncate text-xs">{{ props.user.email }}</span>
      </DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="signOut">
        <LogOut aria-hidden="true" />
        {{ props.labels.sign_out }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
