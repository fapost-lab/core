<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AssistantSwitcher from './AssistantSwitcher.vue'
import NavLink from './NavLink.vue'
import { findActive } from './nav'
import type { ShellPageProps } from './types'

/**
 * The side menu: brand, the assistant switcher (assistant screens only) and the grouped navigation.
 * Used both in the fixed sidebar and in the mobile sheet.
 */
const page = usePage<ShellPageProps>()

const t = computed(() => page.props.translations.console)
const groups = computed(() => page.props.navigation?.groups ?? [])
const active = computed(() => findActive(groups.value, page.url))
</script>

<template>
  <div class="flex h-full flex-col gap-4 p-3">
    <div class="px-2 pt-1">
      <span class="font-display text-lg font-semibold tracking-wide uppercase">FaPost</span>
    </div>

    <AssistantSwitcher
      v-if="page.props.assistants"
      :data="page.props.assistants"
      :label="t.switcher.label"
      :back-label="t.switcher.back_to_admin"
    />

    <nav class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto" aria-label="Main">
      <div v-for="(group, index) in groups" :key="group.label ?? `group-${index}`" class="flex flex-col gap-1">
        <h2
          v-if="group.label"
          class="font-display text-muted-foreground px-2.5 pt-1 text-xs font-medium tracking-wider uppercase"
        >
          {{ group.label }}
        </h2>
        <NavLink
          v-for="item in group.items"
          :key="item.key"
          :href="item.href"
          :label="item.label"
          :icon="item.icon"
          :badge="item.badge"
          :external="item.external"
          :active="active?.key === item.key"
        />
      </div>
    </nav>
  </div>
</template>
