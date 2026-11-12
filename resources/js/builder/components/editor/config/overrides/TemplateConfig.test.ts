// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            translations: {
                builder: {
                    nodes: {
                        template: {
                            template_value: 'Template value',
                            inherited: 'Template value (inherited)',
                            reset_parameter: 'Reset to template',
                            remove_parameter: 'Remove',
                            parameter_orphan: 'Orphan',
                            parameter_languages: 'Base language only',
                        },
                    },
                },
            },
        },
    }),
}))

import TemplateConfig from './TemplateConfig.vue'
import FlowPickerField from '../fields/FlowPickerField.vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'

const snapshot = [
    {key: 'greeting', label: 'Greeting', node_type: 'send_message', node_version: 1, field: 'text'},
    {key: 'is_vip', label: 'VIP only', node_type: 'gate', node_version: 1, field: 'vip'},
    {key: 'mode', label: 'Mode', node_type: 'gate', node_version: 1, field: 'mode'},
    {key: 'wait', label: 'Wait', node_type: 'gate', node_version: 1, field: 'wait'},
]

function mountConfig(values: Record<string, unknown> | undefined, defaults: Record<string, unknown> | null = {
    greeting: {en: 'Hello', uk: 'Привіт'},
    is_vip: true,
    mode: 'strict',
    wait: 'PT2H',
}) {
    const builder = useBuilderStore()
    builder.contentBaseLanguage = 'en'
    builder.availableTemplates = [{
        id: 'tpl-1',
        name: 'Gate',
        version: 1,
        outputs: [],
        parameters: defaults === null ? [] : snapshot.map((p) => ({...p, default: defaults[p.key]})),
    }]

    const node = {id: 'r', type: 'template', version: 1, config: {
        template_flow_id: 'tpl-1',
        outputs: [],
        parameters: snapshot,
        ...(values === undefined ? {} : {parameter_values: values}),
    }}

    return mount(TemplateConfig, {props: {node, schema: {}}})
}

function lastPatch(wrapper: ReturnType<typeof mountConfig>): Record<string, unknown> {
    const events = wrapper.emitted('update:config') as Array<[Record<string, unknown>]>
    return events[events.length - 1][0]
}

function rowOf(wrapper: ReturnType<typeof mountConfig>, label: string) {
    const row = wrapper.findAll('.config-field').find((el) => el.find('.field-label').text() === label)
    if (!row) throw new Error(`no row ${label}`)
    return row
}

describe('TemplateConfig parameters', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        useRegistryStore().nodeTypes = [
            {type: 'send_message', version: 1, config_schema: {text: {type: 'text'}}},
            {type: 'gate', version: 1, config_schema: {
                vip: {type: 'boolean'},
                mode: {type: 'enum-cards', options: [{value: 'lenient', label: 'Lenient'}, {value: 'strict', label: 'Strict'}]},
                wait: {type: 'duration'},
            }},
        ] as never
    })

    it('writes a translatable value as a map holding the base language and keeps the other languages', async () => {
        const wrapper = mountConfig({greeting: {en: 'Hello', uk: 'Привіт'}})

        await rowOf(wrapper, 'Greeting').find('textarea').setValue('Hi there')

        expect(lastPatch(wrapper)).toEqual({parameter_values: {greeting: {en: 'Hi there', uk: 'Привіт'}}})
    })

    it('stores a boolean the host sets, and false is a value', async () => {
        const wrapper = mountConfig(undefined)

        await rowOf(wrapper, 'VIP only').find('input[type=checkbox]').setValue(false)

        expect(lastPatch(wrapper)).toEqual({parameter_values: {is_vip: false}})
    })

    it('removes a key the host clears and drops parameter_values with the last key', async () => {
        const wrapper = mountConfig({greeting: {en: 'Hello'}, wait: 'PT5M'})

        await rowOf(wrapper, 'Greeting').find('textarea').setValue('')
        expect(lastPatch(wrapper)).toEqual({parameter_values: {wait: 'PT5M'}})

        const single = mountConfig({greeting: {en: 'Hello'}})
        await rowOf(single, 'Greeting').find('textarea').setValue('')
        expect(lastPatch(single)).toEqual({parameter_values: undefined})
    })

    it('resets one parameter to the template and offers reset only for a set value', async () => {
        const wrapper = mountConfig({wait: 'PT5M', is_vip: false})

        expect(rowOf(wrapper, 'Greeting').find('.template-param-action').exists()).toBe(false)

        await rowOf(wrapper, 'Wait').find('.template-param-action').trigger('click')

        expect(lastPatch(wrapper)).toEqual({parameter_values: {is_vip: false}})
    })

    it('shows an orphan key the template no longer has and removes it', async () => {
        const wrapper = mountConfig({gone: 'x', is_vip: true})

        const orphan = wrapper.findAll('.config-field').find((el) => el.find('code').exists() && el.find('code').text() === 'gone')
        expect(orphan).toBeDefined()

        await orphan!.find('.template-param-action').trigger('click')

        expect(lastPatch(wrapper)).toEqual({parameter_values: {is_vip: true}})
    })

    describe('a parameter the host has not set', () => {
        it('shows a boolean with the value of the template, labelled as inherited, and offers no reset', () => {
            const wrapper = mountConfig(undefined)
            const row = rowOf(wrapper, 'VIP only')

            expect((row.find('input[type=checkbox]').element as HTMLInputElement).checked).toBe(true)
            expect(row.text()).toContain('Enabled')
            expect(row.text()).toContain('Template value (inherited)')
            expect(row.find('.template-param-action').exists()).toBe(false)
        })

        it('shows a false value of the template as off, still labelled', () => {
            const wrapper = mountConfig(undefined, {is_vip: false})
            const row = rowOf(wrapper, 'VIP only')

            expect((row.find('input[type=checkbox]').element as HTMLInputElement).checked).toBe(false)
            expect(row.text()).toContain('Template value (inherited)')
        })

        it('shows an off control and no label when the value of the template is unknown', () => {
            const wrapper = mountConfig(undefined, null)
            const row = rowOf(wrapper, 'VIP only')

            expect((row.find('input[type=checkbox]').element as HTMLInputElement).checked).toBe(false)
            expect(row.text()).not.toContain('Template value')
        })

        it('shows the option of the template as active in the cards and its duration', () => {
            const wrapper = mountConfig(undefined)

            const active = rowOf(wrapper, 'Mode').find('input[type=radio]:checked')
            expect((active.element as HTMLInputElement).value).toBe('strict')
            expect(rowOf(wrapper, 'Mode').text()).toContain('Template value (inherited)')

            expect((rowOf(wrapper, 'Wait').find('input[type=number]').element as HTMLInputElement).value).toBe('2')
            expect(rowOf(wrapper, 'Wait').text()).toContain('Template value (inherited)')
        })
    })

    it('shows the template value as a placeholder, not as the value, for the state picker', () => {
        useRegistryStore().nodeTypes = [
            {type: 'gate', version: 1, config_schema: {path: {type: 'state-picker'}}},
        ] as never
        const parameters = [
            {key: 'path', label: 'Path', node_type: 'gate', node_version: 1, field: 'path'},
        ]
        const builder = useBuilderStore()
        builder.availableTemplates = [{id: 'tpl-1', name: 'Gate', version: 1, outputs: [], parameters: parameters.map((p) => ({...p, default: 'flow.a'}))}] as never
        const wrapper = mount(TemplateConfig, {props: {schema: {}, node: {id: 'r', type: 'template', version: 1, config: {template_flow_id: 'tpl-1', outputs: [], parameters}}}})

        const path = rowOf(wrapper, 'Path').find('input')
        expect((path.element as HTMLInputElement).value).toBe('')
        expect(path.attributes('placeholder')).toBe('flow.a')
        expect(rowOf(wrapper, 'Path').text()).not.toContain('(inherited)')
    })

    it('shows the flow of the template by name in the flow picker, stores an explicit pick and resets to inheritance', async () => {
        useRegistryStore().nodeTypes = [
            {type: 'gate', version: 1, config_schema: {target: {type: 'flow-picker'}}},
        ] as never
        const parameters = [{key: 'target', label: 'Target', node_type: 'gate', node_version: 1, field: 'target'}]
        const builder = useBuilderStore()
        builder.availableFlows = [{id: 'flow-1', name: 'Support'}, {id: 'flow-2', name: 'Sales'}] as never
        builder.availableTemplates = [{id: 'tpl-1', name: 'Gate', version: 1, outputs: [], parameters: parameters.map((p) => ({...p, default: 'flow-1'}))}] as never
        const node = (values?: Record<string, unknown>) => ({id: 'r', type: 'template', version: 1, config: {template_flow_id: 'tpl-1', outputs: [], parameters, ...(values ? {parameter_values: values} : {})}})

        const inherited = mount(TemplateConfig, {props: {schema: {}, node: node()}})
        const row = rowOf(inherited, 'Target')
        expect(row.find('.ss-trigger-label').text()).toBe('Support')
        expect(row.text()).toContain('Template value (inherited)')
        expect(row.find('.template-param-action').exists()).toBe(false)

        row.findComponent(FlowPickerField).vm.$emit('update:value', 'flow-2')
        expect(lastPatch(inherited)).toEqual({parameter_values: {target: 'flow-2'}})

        const set = mount(TemplateConfig, {props: {schema: {}, node: node({target: 'flow-2'})}})
        const setRow = rowOf(set, 'Target')
        expect(setRow.find('.ss-trigger-label').text()).toBe('Sales')
        expect(setRow.text()).not.toContain('(inherited)')
        await setRow.find('.template-param-action').trigger('click')
        expect(lastPatch(set)).toEqual({parameter_values: undefined})
    })

    it('shows the choice of the host, without the label, once it is set', () => {
        const wrapper = mountConfig({is_vip: false, mode: 'lenient'})
        const row = rowOf(wrapper, 'VIP only')

        expect((row.find('input[type=checkbox]').element as HTMLInputElement).checked).toBe(false)
        expect(row.text()).not.toContain('(inherited)')
        expect(row.text()).toContain('Template value: true')
        expect(row.find('.template-param-action').exists()).toBe(true)
        expect((rowOf(wrapper, 'Mode').find('input[type=radio]:checked').element as HTMLInputElement).value).toBe('lenient')
    })
})
