<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { Trash2 } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Checkbox } from '@fapost/ui/components/checkbox'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Label } from '@fapost/ui/components/label'
import { interpolate } from '@fapost/ui/shell'
import { toggleValue } from './roles'
import type { EditableUser, RoleOption, UsersPageProps } from './types'

/**
 * A staff user's profile, password and roles. The roles are sent only when the actor may change them, and only the
 * ones below the actor's priority are offered; roles out of reach are listed and kept by the server.
 */
const props = defineProps<{
  user: EditableUser
  roles: RoleOption[]
  can: { editRoles: boolean; delete: boolean }
  urls: { index: string; submit: string; destroy: string }
}>()

const page = usePage<UsersPageProps>()
const t = computed(() => page.props.translations.console.users)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.user.name,
  email: props.user.email,
  phone: props.user.phone ?? '',
  password: '',
  roles: [...props.user.roles],
})

const deleteOpen = ref(false)

function submit(): void {
  form
    .transform((data) => {
      const { roles, ...profile } = data

      return props.can.editRoles ? { ...profile, roles } : profile
    })
    .put(props.urls.submit, { preserveScroll: true, onSuccess: () => form.reset('password') })
}

function destroy(): void {
  router.delete(props.urls.destroy)
}
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.edit_title }}</h1>
      <Button v-if="can.delete" variant="outline-danger" size="sm" @click="deleteOpen = true">
        <Trash2 aria-hidden="true" />
        {{ t.delete_user }}
      </Button>
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

      <FormSection :title="t.sections.password.title" :description="t.sections.password.description">
        <FormField id="password" :label="t.fields.password" :error="form.errors.password" v-slot="{ invalid, describedBy }">
          <Input id="password" v-model="form.password" name="password" type="password" maxlength="255" autocomplete="new-password" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>
      </FormSection>

      <FormSection :title="t.sections.access.title" :description="t.sections.access.description">
        <p v-if="!can.editRoles" class="text-muted-foreground text-sm">{{ t.roles_locked }}</p>

        <template v-else>
          <fieldset class="flex flex-col gap-2" :aria-describedby="form.errors.roles ? 'roles-error' : undefined">
            <legend class="mb-1 text-sm font-medium">{{ t.fields.roles }}</legend>
            <p v-if="roles.length === 0" class="text-muted-foreground text-sm">{{ t.fields.no_roles }}</p>
            <div v-for="role in roles" :key="role.value" class="flex items-center gap-2">
              <Checkbox
                :id="`role-${role.value}`"
                :model-value="form.roles.includes(role.value)"
                @update:model-value="(checked) => (form.roles = toggleValue(form.roles, role.value, checked === true))"
              />
              <Label :for="`role-${role.value}`" class="cursor-pointer font-normal">{{ role.label }}</Label>
            </div>
            <p v-if="form.errors.roles" id="roles-error" role="alert" class="text-destructive text-sm">{{ form.errors.roles }}</p>
          </fieldset>
        </template>

        <p v-if="user.keptRoles.length > 0" class="text-muted-foreground text-sm">
          {{ interpolate(can.editRoles ? t.fields.kept_roles : t.fields.current_roles, { roles: user.keptRoles.join(', ') }) }}
        </p>
      </FormSection>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
        <Button as-child variant="ghost">
          <Link :href="urls.index">{{ common.cancel }}</Link>
        </Button>
      </div>
    </form>

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t.delete_one.title"
      :description="interpolate(t.delete_one.description, { name: user.name })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="destroy"
    />
  </div>
</template>
