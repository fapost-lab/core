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
  <div class="flex h-full flex-col gap-[18px] px-3 py-4">
    <div class="flex items-center gap-2.5 px-2 py-1">
      <span class="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-lg text-sm font-bold" aria-hidden="true">F</span>
      <span class="font-display text-[19px] font-bold">FaPost</span>
    </div>

    <AssistantSwitcher
      v-if="page.props.assistants"
      :data="page.props.assistants"
      :label="t.switcher.label"
      :back-label="t.switcher.back_to_admin"
    />

    <nav class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto" aria-label="Main">
      <div v-for="(group, index) in groups" :key="group.label ?? `group-${index}`" class="flex flex-col gap-0.5">
        <h2
          v-if="group.label"
          class="font-display text-faint-foreground px-2.5 pb-1.5 text-[12.5px] font-semibold tracking-[0.04em] uppercase"
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
