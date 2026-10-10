<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import type { RoleOption, UsersPageProps } from './types'

/** An invitation: the new user's profile and the role they start with. The server validates; its errors show under the fields. */
const props = defineProps<{
  roles: RoleOption[]
  urls: { index: string; submit: string }
}>()

const page = usePage<UsersPageProps>()
const t = computed(() => page.props.translations.console.users)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: '',
  email: '',
  phone: '',
  role_id: '',
})

function submit(): void {
  form.post(props.urls.submit, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>
      <p class="text-muted-foreground">{{ t.invite_hint }}</p>
    </div>

    <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <FormSection :title="t.sections.profile.title" :description="t.sections.profile.description">
        <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <FormField id="email" :label="t.fields.email" :error="form.errors.email" v-slot="{ invalid, describedBy }">
          <Input id="email" v-model="form.email" name="email" type="email" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <FormField id="phone" :label="t.fields.phone" :error="form.errors.phone" v-slot="{ invalid, describedBy }">
          <Input id="phone" v-model="form.phone" name="phone" type="tel" maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>
      </FormSection>

      <FormSection :title="t.sections.access.title" :description="t.sections.access.description">
        <p v-if="roles.length === 0" class="text-muted-foreground text-sm">{{ t.fields.no_roles }}</p>
        <FormField v-else id="role_id" :label="t.fields.role" :error="form.errors.role_id" v-slot="{ invalid, describedBy }">
          <Select :model-value="form.role_id === '' ? undefined : form.role_id" @update:model-value="(value) => (form.role_id = String(value ?? ''))">
            <SelectTrigger id="role_id" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
              <SelectValue :placeholder="t.fields.role_hint" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</SelectItem>
            </SelectContent>
          </Select>
        </FormField>
      </FormSection>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing || roles.length === 0">{{ form.processing ? common.saving : t.new }}</Button>
        <Button as-child variant="ghost">
          <Link :href="urls.index">{{ common.cancel }}</Link>
        </Button>
      </div>
    </form>
  </div>
</template>
