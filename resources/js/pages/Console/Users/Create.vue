<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import InviteForm from './InviteForm.vue'
import type { RoleOption, UsersPageProps } from './types'

/** An invitation: the new user's profile and the role they start with. The server validates; its errors show under the fields. */
defineProps<{
  roles: RoleOption[]
  urls: { index: string; submit: string }
}>()

const page = usePage<UsersPageProps>()
const t = computed(() => page.props.translations.console.users)
const common = computed(() => page.props.translations.console.form)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>
      <p class="text-muted-foreground">{{ t.invite_hint }}</p>
    </div>

    <InviteForm :roles="roles" :submit-url="urls.submit">
      <template #actions>
        <Button as-child variant="ghost">
          <Link :href="urls.index">{{ common.cancel }}</Link>
        </Button>
      </template>
    </InviteForm>
  </div>
</template>
