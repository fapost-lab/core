<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Checkbox } from '@fapost/ui/components/checkbox'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Label } from '@fapost/ui/components/label'
import { interpolate } from '@fapost/ui/shell'
import { toggleValue } from '../Users/roles'
import { selectedInGroup, toggleGroup } from './permissions'
import type { EditableRole, PermissionGroup, RolesPageProps } from './types'

/**
 * The fields of a role, shared by the create and the edit screen: its name (fixed for a system role), display name and
 * the permissions, one checklist per group with a toggle for the whole group. Sensitive permissions carry a warning.
 */
const props = defineProps<{
  initial: EditableRole
  catalogue: PermissionGroup[]
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
}>()

const page = usePage<RolesPageProps>()
const t = computed(() => page.props.translations.console.roles)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
  display_name: props.initial.displayName ?? '',
  permissions: [...props.initial.permissions],
})

function submit(): void {
  form
    .transform((data) => (props.initial.isSystem ? { display_name: data.display_name, permissions: data.permissions } : data))
    .submit(props.method, props.submitUrl, { preserveScroll: true })
}

function groupFull(group: PermissionGroup): boolean {
  return selectedInGroup(group, form.permissions) === group.permissions.length
}
</script>

<template>
  <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
    <FormSection :title="t.sections.general.title" :description="t.sections.general.description">
      <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
        <Input
          id="name"
          v-model="form.name"
          name="name"
          required
          maxlength="255"
          autocomplete="off"
          :disabled="initial.isSystem"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
        />
        <p v-if="initial.isSystem" class="text-muted-foreground text-xs">{{ t.fields.name_system_hint }}</p>
      </FormField>

      <FormField id="display_name" :label="t.fields.display_name" :error="form.errors.display_name" v-slot="{ invalid, describedBy }">
        <Input id="display_name" v-model="form.display_name" name="display_name" maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>
    </FormSection>

    <p v-if="form.errors.permissions" role="alert" class="text-destructive text-sm">{{ form.errors.permissions }}</p>

    <FormSection v-for="group in catalogue" :key="group.key" :title="group.label" :description="interpolate(t.selected_count, { count: selectedInGroup(group, form.permissions), total: group.permissions.length })">
      <div class="flex flex-col gap-3">
        <div>
          <Button type="button" variant="outline" size="sm" @click="form.permissions = toggleGroup(group, form.permissions, !groupFull(group))">
            {{ groupFull(group) ? t.clear_all : t.select_all }}
          </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="permission in group.permissions" :key="permission.value" class="flex items-start gap-2">
            <Checkbox
              :id="`permission-${permission.value}`"
              class="mt-0.5"
              :model-value="form.permissions.includes(permission.value)"
              :aria-describedby="permission.description || permission.sensitive ? `permission-${permission.value}-hint` : undefined"
              @update:model-value="(checked) => (form.permissions = toggleValue(form.permissions, permission.value, checked === true))"
            />
            <div class="flex flex-col gap-0.5">
              <Label :for="`permission-${permission.value}`" class="cursor-pointer font-normal">{{ permission.label }}</Label>
              <div v-if="permission.description || permission.sensitive" :id="`permission-${permission.value}-hint`" class="flex flex-col gap-0.5 text-xs">
                <span v-if="permission.sensitive" class="text-warning-foreground inline-flex items-center gap-1 font-medium">
                  <TriangleAlert class="size-3.5" aria-hidden="true" />
                  {{ t.sensitive }}
                </span>
                <span v-if="permission.description" class="text-muted-foreground">{{ permission.description }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </FormSection>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
