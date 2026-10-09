<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Plus } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Switch } from '@fapost/ui/components/switch'
import { Textarea } from '@fapost/ui/components/textarea'
import NewGroupDialog from './NewGroupDialog.vue'
import type { FlowGroupOption, FlowsPageProps } from './types'

/** The fields of a flow, shared by the create and the edit screen. The server validates; its errors show under the fields. */
const props = defineProps<{
  initial: { name: string; flowGroupId: string | null; description: string | null; isPublic: boolean; loggingEnabled: boolean }
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
  groups: FlowGroupOption[]
  canCreateGroup: boolean
  storeGroupUrl: string
  submitLabel?: string
}>()

// A select item cannot hold an empty value, so "no group" is a value of its own.
const NO_GROUP = '__none'

const page = usePage<FlowsPageProps>()
const t = computed(() => page.props.translations.console.flows)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
  flow_group_id: props.initial.flowGroupId,
  description: props.initial.description ?? '',
  is_public: props.initial.isPublic,
  logging_enabled: props.initial.loggingEnabled,
})

const groupValue = computed({
  get: () => form.flow_group_id ?? NO_GROUP,
  set: (value: string) => {
    form.flow_group_id = value === NO_GROUP ? null : value
  },
})

const newGroupOpen = ref(false)

function submit(): void {
  form.submit(props.method, props.submitUrl, { preserveScroll: true })
}
</script>

<template>
  <div>
    <form class="flex max-w-xl flex-col gap-5" novalidate @submit.prevent="submit">
      <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
        <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField id="flow_group_id" :label="t.fields.group" :error="form.errors.flow_group_id" v-slot="{ invalid, describedBy }">
        <div class="flex items-center gap-2">
          <Select v-model="groupValue">
            <SelectTrigger id="flow_group_id" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="NO_GROUP">{{ t.fields.group_none }}</SelectItem>
              <SelectItem v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</SelectItem>
            </SelectContent>
          </Select>
          <Button v-if="canCreateGroup" type="button" variant="outline" class="shrink-0" @click="newGroupOpen = true">
            <Plus aria-hidden="true" />
            {{ t.new_group }}
          </Button>
        </div>
      </FormField>

      <FormField id="description" :label="t.fields.description" :error="form.errors.description" v-slot="{ invalid, describedBy }">
        <Textarea id="description" v-model="form.description" name="description" rows="3" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField id="is_public" :label="t.fields.is_public" :hint="t.fields.is_public_hint" :error="form.errors.is_public" v-slot="{ invalid, describedBy }">
        <Switch id="is_public" v-model="form.is_public" class="w-fit" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField id="logging_enabled" :label="t.fields.logging_enabled" :hint="t.fields.logging_enabled_hint" :error="form.errors.logging_enabled" v-slot="{ invalid, describedBy }">
        <Switch id="logging_enabled" v-model="form.logging_enabled" class="w-fit" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : (submitLabel ?? common.save) }}</Button>
        <Button as-child variant="ghost">
          <Link :href="cancelUrl">{{ common.cancel }}</Link>
        </Button>
      </div>
    </form>

    <NewGroupDialog v-if="canCreateGroup" v-model:open="newGroupOpen" :submit-url="storeGroupUrl" @created="(id) => (groupValue = id)" />
  </div>
</template>
