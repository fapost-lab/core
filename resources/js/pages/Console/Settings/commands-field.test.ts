// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { translationPaths } from '../../../console/solutions/screen-test-support'
import CommandsField from './CommandsField.vue'
import { newCommand } from './settings'
import type { CommandState, CommandTypeOption } from './types'

/**
 * A command whose action a Solution registered: its config is edited through the form its schema describes, keys the
 * form does not show survive an edit, choosing the action fills in its defaults, a Solution that is off is said so and
 * offered only where it is chosen, and a type no Solution registers stays read-only.
 */
const { page } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/assistant/a1/settings' } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
}))

const builtins: CommandTypeOption[] = [
  { value: 'terminate_session', label: 'Terminate', help: 'Ends' },
  { value: 'start_flow', label: 'Start flow', help: 'Starts' },
  { value: 'send_message', label: 'Send', help: 'Sends' },
]

const thank: CommandTypeOption = {
  value: 'feedback.thank',
  label: 'Thank the contact',
  help: '',
  available: true,
  schema: {
    required: ['text'],
    text: { type: 'string', label: 'Reply', default: 'Thanks!' },
    times: { type: 'number', label: 'Times' },
    payload: { type: 'json', label: 'Payload' },
  },
}

function row(overrides: Partial<CommandState> = {}): CommandState {
  return { ...newCommand(['en'], 'k'), command: 'thanks', type: 'feedback.thank', config: { text: 'Hi', hidden: 'kept', payload: { a: 1 } }, warnings: [], ...overrides }
}

function mountField(commands: CommandState[], types: CommandTypeOption[] = [...builtins, thank], errors: Record<string, string | undefined> = {}) {
  const model = ref(commands)

  const wrapper = mount(
    defineComponent({
      setup: () => () =>
        h(CommandsField, {
          modelValue: model.value,
          'onUpdate:modelValue': (value: CommandState[]) => {
            model.value = value
          },
          languages: ['en'],
          types,
          flows: [],
          canCreateFlow: false,
          errors,
        }),
    }),
  )

  return { wrapper, model }
}

beforeEach(() => {
  page.props = { translations: { console: translationPaths('console') }, locale: 'en' }
})

describe('the config of a Solution action', () => {
  it('is edited field by field, keeping what the form does not show', async () => {
    const { wrapper, model } = mountField([row()])

    const reply = wrapper.find<HTMLInputElement>('#command-0-config-text')
    expect(reply.element.value).toBe('Hi')

    await reply.setValue('Thank you')
    await wrapper.find('#command-0-config-times').setValue('3')

    expect(model.value[0].config).toEqual({ text: 'Thank you', hidden: 'kept', payload: { a: 1 }, times: 3 })
  })

  it('shows a field it cannot edit as stored, with no control', () => {
    const { wrapper } = mountField([row()])

    expect(wrapper.find('pre#command-0-config-payload').text()).toContain('"a": 1')
    expect(wrapper.text()).toContain('console.settings.commands.config_unsupported')
  })

  it('shows the errors and warnings of its fields', () => {
    const { wrapper } = mountField([row({ warnings: [{ field: 'text', message: 'A long reply may be cut.' }] })], undefined, { 'commands.0.config.text': 'The reply text is required.' })

    expect(wrapper.text()).toContain('The reply text is required.')
    expect(wrapper.find('[role="status"]').text()).toContain('A long reply may be cut.')
  })

  it('shows above the fields an error of a field it does not show', () => {
    const { wrapper } = mountField([row()], undefined, { 'commands.0.config.items.0.name': 'A name is required.', 'commands.0.config': 'Incomplete.' })

    const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
    expect(alerts).toContain('A name is required.')
    expect(alerts).toContain('Incomplete.')
  })

  it('starts from the schema defaults when the action is chosen, and drops the config for a Core action', async () => {
    const { wrapper, model } = mountField([{ ...newCommand(['en'], 'k'), command: 'thanks' }])
    const select = wrapper.findAllComponents({ name: 'SelectRoot' })[0]

    select.vm.$emit('update:modelValue', 'feedback.thank')
    await wrapper.vm.$nextTick()
    expect(model.value[0]).toMatchObject({ type: 'feedback.thank', config: { text: 'Thanks!' } })

    wrapper.findAllComponents({ name: 'SelectRoot' })[0].vm.$emit('update:modelValue', 'send_message')
    await wrapper.vm.$nextTick()
    expect(model.value[0]).toMatchObject({ type: 'send_message', config: null })
  })

  it('says when its Solution is off', () => {
    const { wrapper } = mountField([row()], [...builtins, { ...thank, available: false }])

    expect(wrapper.text()).toContain('console.settings.commands.solution_inactive')
    expect(wrapper.find('#command-0-config-text').exists()).toBe(true)
  })
})

describe('a type no Solution registers', () => {
  it('is shown read-only', () => {
    const { wrapper } = mountField([row({ type: 'hr.vacation', extension: { type: 'hr.vacation', label: 'hr.vacation', installed: false } })])

    expect(wrapper.text()).toContain('console.settings.commands.extension_not_installed')
    expect(wrapper.find('#command-0-config-text').exists()).toBe(false)
  })
})
