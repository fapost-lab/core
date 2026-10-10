<script setup lang="ts">
import { computed } from 'vue'
import { Plus } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import type { FlowOption } from './types'

/**
 * Chooses one of the assistant's flows, or none, with a button beside it that creates a new flow for this very choice
 * (offered only while one may be created). A switched-off flow that is already chosen stays listed, marked.
 */
const props = defineProps<{
  id: string
  options: FlowOption[]
  placeholder: string
  inactiveLabel: string
  createLabel: string
  canCreate: boolean
  /** Whether "no flow" is a choice (the default flow); a command that starts a flow needs one. */
  clearable?: boolean
  invalid?: boolean
  describedBy?: string
}>()

const flowId = defineModel<string | null>({ required: true })

defineEmits<{ create: [] }>()

// The select cannot hold an empty value, so "no flow" is a value of its own.
const NONE = '__none__'

const value = computed({
  get: () => flowId.value ?? (props.clearable ? NONE : undefined),
  set: (chosen: string | undefined) => {
    flowId.value = chosen === undefined || chosen === NONE ? null : chosen
  },
})
</script>

<template>
  <div class="flex items-center gap-2">
    <Select v-model="value">
      <SelectTrigger :id="id" class="min-w-0 flex-1" :aria-invalid="invalid" :aria-describedby="describedBy">
        <SelectValue :placeholder="placeholder" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-if="clearable" :value="NONE">{{ placeholder }}</SelectItem>
        <SelectItem v-for="flow in options" :key="flow.value" :value="flow.value">
          {{ flow.label }}<span v-if="!flow.active" class="text-muted-foreground"> · {{ inactiveLabel }}</span>
        </SelectItem>
      </SelectContent>
    </Select>
    <Button v-if="canCreate" type="button" variant="outline" size="icon" :aria-label="createLabel" :title="createLabel" @click="$emit('create')">
      <Plus aria-hidden="true" />
    </Button>
  </div>
</template>
