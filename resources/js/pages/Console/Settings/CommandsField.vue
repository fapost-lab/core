<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { ChevronDown, ChevronRight, Plus, Trash2 } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { interpolate } from '@fapost/ui/shell'
import FlowSelect from './FlowSelect.vue'
import LocalizedTextField from './LocalizedTextField.vue'
import { commandErrors, errorsUnder, newCommand } from './settings'
import type { CommandState, CommandType, CommandTypeOption, FlowOption, SettingsPageProps } from './types'

/**
 * The slash commands, as collapsible cards in their stored order. Each shows only the fields its action uses: an
 * acknowledgement for ending the session, a flow for starting one (with a button that creates it), a message for
 * sending one. Errors arrive keyed by the command's position.
 */
const props = defineProps<{
  languages: string[]
  types: CommandTypeOption[]
  flows: FlowOption[]
  canCreateFlow: boolean
  errors: Record<string, string | undefined>
}>()

const commands = defineModel<CommandState[]>({ required: true })

const emit = defineEmits<{ createFlow: [index: number] }>()

const page = usePage<SettingsPageProps>()
const t = computed(() => page.props.translations.console.settings)

const collapsed = ref<Set<string>>(new Set())
let added = 0

const helpOf = computed(() => new Map(props.types.map((type) => [type.value, type.help])))
const labelOf = computed(() => new Map(props.types.map((type) => [type.value, type.label])))

function titleOf(command: CommandState): string {
  const name = command.command.trim()

  return name === '' ? t.value.commands.untitled : `/${name.replace(/^\/+/, '')}`
}

function update(index: number, patch: Partial<CommandState>): void {
  commands.value = commands.value.map((command, at) => (at === index ? { ...command, ...patch } : command))
}

function add(): void {
  commands.value = [...commands.value, newCommand(props.languages, `new-${++added}`)]
}

function remove(index: number): void {
  commands.value = commands.value.filter((_, at) => at !== index)
}

function toggle(key: string): void {
  const next = new Set(collapsed.value)

  if (next.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }

  collapsed.value = next
}

function hasErrors(index: number): boolean {
  return Object.values(commandErrors(props.errors, index)).some((message) => message !== undefined)
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <p v-if="errors.commands" role="alert" class="text-destructive text-[12.5px]">{{ errors.commands }}</p>

    <p v-if="commands.length === 0" class="text-muted-foreground text-sm">{{ t.commands.empty }}</p>

    <div v-for="(command, index) in commands" :key="command.key" class="rounded-lg border" :class="hasErrors(index) ? 'border-invalid' : ''">
      <div class="flex items-center gap-2 px-3 py-2">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          :aria-expanded="!collapsed.has(command.key) || hasErrors(index)"
          :aria-label="interpolate(collapsed.has(command.key) ? t.commands.expand : t.commands.collapse, { command: titleOf(command) })"
          @click="toggle(command.key)"
        >
          <ChevronRight v-if="collapsed.has(command.key) && !hasErrors(index)" aria-hidden="true" />
          <ChevronDown v-else aria-hidden="true" />
        </Button>
        <span class="min-w-0 flex-1 truncate font-medium">{{ titleOf(command) }}</span>
        <Badge v-if="command.type !== ''" variant="neutral">{{ labelOf.get(command.type) }}</Badge>
        <Button type="button" variant="ghost" size="icon-sm" :aria-label="interpolate(t.commands.remove, { command: titleOf(command) })" @click="remove(index)">
          <Trash2 aria-hidden="true" />
        </Button>
      </div>

      <div v-if="!collapsed.has(command.key) || hasErrors(index)" class="grid gap-[18px] border-t p-4">
        <div class="grid gap-[18px] sm:grid-cols-2">
          <FormField :id="`command-${index}`" :label="t.commands.fields.command" :error="commandErrors(errors, index).command" v-slot="{ invalid, describedBy }">
            <div class="flex items-center">
              <span class="border-input bg-surface-muted text-muted-foreground flex h-10 items-center rounded-l-md border border-r-0 px-3 text-sm" aria-hidden="true">/</span>
              <Input
                :id="`command-${index}`"
                :model-value="command.command"
                class="rounded-l-none"
                required
                maxlength="64"
                placeholder="start"
                autocomplete="off"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="(value) => update(index, { command: String(value) })"
              />
            </div>
          </FormField>

          <FormField :id="`command-${index}-type`" :label="t.commands.fields.type" :hint="command.type === '' ? undefined : helpOf.get(command.type)" :error="commandErrors(errors, index).type" v-slot="{ invalid, describedBy }">
            <Select :model-value="command.type === '' ? undefined : command.type" @update:model-value="(value) => update(index, { type: value as CommandType })">
              <SelectTrigger :id="`command-${index}-type`" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
                <SelectValue :placeholder="t.commands.fields.type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</SelectItem>
              </SelectContent>
            </Select>
          </FormField>
        </div>

        <FormField v-if="command.type === 'terminate_session'" :id="`command-${index}-response`" :label="t.commands.fields.response">
          <LocalizedTextField
            :id="`command-${index}-response`"
            :model-value="command.response"
            :label="t.commands.fields.response"
            :languages="languages"
            :errors="errorsUnder(commandErrors(errors, index), 'response')"
            :rows="2"
            @update:model-value="(value) => update(index, { response: value })"
          />
        </FormField>

        <FormField
          v-if="command.type === 'start_flow'"
          :id="`command-${index}-flow`"
          :label="t.commands.fields.flow"
          :hint="command.flowMissing && command.flowId === null ? t.fields.flow_missing : undefined"
          :error="commandErrors(errors, index).flow_id"
          v-slot="{ invalid, describedBy }"
        >
          <FlowSelect
            :id="`command-${index}-flow`"
            :model-value="command.flowId"
            :options="flows"
            :placeholder="t.fields.flow_placeholder"
            :inactive-label="t.fields.flow_inactive"
            :create-label="t.new_flow.open"
            :can-create="canCreateFlow"
            :invalid="invalid"
            :described-by="describedBy"
            @update:model-value="(value) => update(index, { flowId: value })"
            @create="emit('createFlow', index)"
          />
        </FormField>

        <FormField v-if="command.type === 'send_message'" :id="`command-${index}-text`" :label="t.commands.fields.text">
          <LocalizedTextField
            :id="`command-${index}-text`"
            :model-value="command.text"
            :label="t.commands.fields.text"
            :languages="languages"
            :errors="errorsUnder(commandErrors(errors, index), 'text')"
            :rows="2"
            @update:model-value="(value) => update(index, { text: value })"
          />
        </FormField>
      </div>
    </div>

    <div>
      <Button type="button" variant="outline" @click="add">
        <Plus aria-hidden="true" />
        {{ t.commands.add }}
      </Button>
    </div>
  </div>
</template>
