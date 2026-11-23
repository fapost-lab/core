<script setup lang="ts">
/**
 * Config panel of `ask_person`. The generic schema renderer cannot mint the UUID ids the answer buttons need (they
 * are the edge handles), so this core override owns the button list.
 */
import {computed, onMounted, ref, watch} from 'vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import {useTranslations} from '@builder/composables/useTranslations'
import VariablePicker from '../VariablePicker.vue'
import AccordionSection from '../AccordionSection.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {compileVariable, decodeSendMessageVariable} from '@builder/utils/variableCompiler'
import type {Variable} from '@builder/dto/types'

const MAX_BUTTONS = 8
const MIN_TIMEOUT = 60
const MAX_TIMEOUT = 604800

const ANSWER_TYPES = [
    {value: 'text', label: 'Text'},
    {value: 'number', label: 'Number'},
    {value: 'confirm', label: 'Yes/No'},
]

interface AnswerButton {
    id: string
    label: unknown
    value: string
}

const props = defineProps({
    node:   {type: Object as () => Record<string, unknown>, required: true},
    schema: {type: Object as () => Record<string, unknown>, default: () => ({})},
})

const emit = defineEmits(['update:config'])
const {t} = useTranslations()
const knownGroups = useKnownGroups()

const config = computed((): Record<string, unknown> => (props.node.config as Record<string, unknown>) ?? {})
const buttons = computed((): AnswerButton[] => (Array.isArray(config.value.buttons) ? (config.value.buttons as AnswerButton[]) : []))
const canAdd = computed(() => buttons.value.length < MAX_BUTTONS)

function update(patch: Record<string, unknown>) {
    emit('update:config', patch)
}

/** A translated value is edited in the Content tab; here it is shown read-only. */
function isTranslated(value: unknown): boolean {
    return value !== null && typeof value === 'object'
}

function plain(value: unknown): string {
    if (typeof value === 'string') return value
    if (isTranslated(value)) return String(Object.values(value as Record<string, unknown>)[0] ?? '')
    return ''
}

function newButton(label: string, value: string): AnswerButton {
    return {id: crypto.randomUUID(), label, value}
}

// A fresh node starts with two answers so there is something to wire right away.
onMounted(() => {
    if (buttons.value.length === 0) {
        update({buttons: [newButton(t('nodes.ask_person.default_approve'), 'yes'), newButton(t('nodes.ask_person.default_reject'), 'no')]})
    }
})

function addButton() {
    if (!canAdd.value) return
    update({buttons: [...buttons.value, newButton('', '')]})
}

function patchButton(index: number, patch: Partial<AnswerButton>) {
    update({buttons: buttons.value.map((btn, i) => (i === index ? {...btn, ...patch} : btn))})
}

function removeButton(index: number) {
    update({buttons: buttons.value.filter((_, i) => i !== index)})
}

function moveButton(index: number, delta: number) {
    const target = index + delta
    if (target < 0 || target >= buttons.value.length) return
    const next = [...buttons.value]
    ;[next[index], next[target]] = [next[target], next[index]]
    update({buttons: next})
}

// ── Addressee ────────────────────────────────────────────────────────────────

const addresseeContact = computed(() => String((config.value.addressee as Record<string, unknown> | null)?.contact ?? ''))
const addresseeRef = ref<HTMLInputElement | null>(null)

function updateAddressee(contact: string) {
    update({addressee: {kind: 'contact', contact}})
}

const insertAddressee = useInsertAtCursor(addresseeRef, (next) => updateAddressee(next))

// ── Question and late answer ─────────────────────────────────────────────────

const textRef = ref<HTMLTextAreaElement | null>(null)
const insertText = useInsertAtCursor(textRef, (next) => update({text: next}))

// ── Timeout ──────────────────────────────────────────────────────────────────

function updateTimeout(raw: string) {
    const n = Number(raw)
    update({timeout_seconds: raw === '' || Number.isNaN(n) ? null : Math.min(MAX_TIMEOUT, Math.max(MIN_TIMEOUT, Math.round(n)))})
}

// ── Saved answer ─────────────────────────────────────────────────────────────

const answerVariable = ref<Variable | null>(decodeSendMessageVariable(config.value))
const saveAnswerEnabled = ref<boolean>(answerVariable.value !== null)

watch(() => props.node.id, () => {
    answerVariable.value = decodeSendMessageVariable(config.value)
    saveAnswerEnabled.value = answerVariable.value !== null
})

function onAnswerVariableUpdate(next: Variable) {
    answerVariable.value = next
    update({save_to_variable: compileVariable(next)})
}

function onSaveAnswerToggle(enabled: boolean) {
    saveAnswerEnabled.value = enabled
    if (!enabled) {
        answerVariable.value = null
        update({save_to_variable: undefined})
    }
}
</script>

<template>
    <div class="accordion">
        <AccordionSection :title="t('nodes.ask_person.section_addressee')" default-open>
            <div class="config-field">
                <div class="field-label field-label--row">
                    {{ t('nodes.ask_person.addressee') }}
                    <VariablePicker @select="insertAddressee" />
                </div>
                <input
                    ref="addresseeRef"
                    class="field-input"
                    data-test="addressee-contact"
                    :value="addresseeContact"
                    placeholder="{{flow.manager_id}}"
                    @change="updateAddressee(($event.target as HTMLInputElement).value)"
                >
                <p class="field-hint">{{ t('nodes.ask_person.addressee_help') }}</p>
            </div>
        </AccordionSection>

        <AccordionSection :title="t('nodes.ask_person.section_question')" default-open>
            <div class="config-field">
                <div class="field-label field-label--row">
                    {{ t('nodes.ask_person.text') }}
                    <VariablePicker @select="insertText" />
                </div>
                <textarea
                    ref="textRef"
                    class="field-input"
                    data-test="question-text"
                    rows="4"
                    :disabled="isTranslated(config.text)"
                    :value="plain(config.text)"
                    @input="update({text: ($event.target as HTMLTextAreaElement).value})"
                />
                <p class="field-hint">{{ t('nodes.ask_person.text_help') }}</p>
            </div>
        </AccordionSection>

        <AccordionSection :title="t('nodes.ask_person.section_buttons')" :badge="buttons.length > 0 ? buttons.length : null" default-open>
            <div v-for="(btn, index) in buttons" :key="btn.id" class="config-field ask-person-button" data-test="answer-button">
                <input
                    class="field-input"
                    data-test="button-label"
                    :placeholder="t('nodes.ask_person.button_label')"
                    :disabled="isTranslated(btn.label)"
                    :value="plain(btn.label)"
                    @input="patchButton(index, {label: ($event.target as HTMLInputElement).value})"
                >
                <input
                    class="field-input"
                    data-test="button-value"
                    :placeholder="t('nodes.ask_person.button_value')"
                    :value="btn.value"
                    @input="patchButton(index, {value: ($event.target as HTMLInputElement).value})"
                >
                <div class="ask-person-button-actions">
                    <button type="button" class="node-side-btn" data-test="button-up" :disabled="index === 0" @click="moveButton(index, -1)">↑</button>
                    <button type="button" class="node-side-btn" data-test="button-down" :disabled="index === buttons.length - 1" @click="moveButton(index, 1)">↓</button>
                    <button type="button" class="node-side-btn" data-test="button-remove" @click="removeButton(index)">×</button>
                </div>
            </div>
            <button type="button" class="btn-add" data-test="add-button" :disabled="!canAdd" @click="addButton">
                {{ t('nodes.ask_person.add_button') }}
            </button>
        </AccordionSection>

        <AccordionSection :title="t('nodes.ask_person.section_wait')" default-open>
            <div class="config-field">
                <div class="field-label">{{ t('nodes.ask_person.timeout_seconds') }}</div>
                <input
                    class="field-input"
                    data-test="timeout-seconds"
                    type="number"
                    :min="MIN_TIMEOUT"
                    :max="MAX_TIMEOUT"
                    :value="(config.timeout_seconds as number | string | undefined) ?? ''"
                    @change="updateTimeout(($event.target as HTMLInputElement).value)"
                >
                <p class="field-hint">{{ t('nodes.ask_person.timeout_seconds_help') }}</p>
            </div>
        </AccordionSection>

        <AccordionSection :title="t('nodes.ask_person.section_answer')">
            <div class="config-field">
                <div class="field-label">{{ t('nodes.ask_person.late_answer_text') }}</div>
                <textarea
                    class="field-input"
                    data-test="late-answer-text"
                    rows="2"
                    :disabled="isTranslated(config.late_answer_text)"
                    :value="plain(config.late_answer_text)"
                    @input="update({late_answer_text: ($event.target as HTMLTextAreaElement).value})"
                />
                <p class="field-hint">{{ t('nodes.ask_person.late_answer_text_help') }}</p>
            </div>
            <div class="config-field">
                <label class="save-answer-toggle">
                    <input
                        type="checkbox"
                        class="toggle-check"
                        data-test="save-answer"
                        :checked="saveAnswerEnabled"
                        @change="onSaveAnswerToggle(($event.target as HTMLInputElement).checked)"
                    >
                    <span class="save-answer-label">{{ t('nodes.ask_person.save_to_variable') }}</span>
                </label>
                <VariableStorageEditor
                    v-if="saveAnswerEnabled"
                    :model-value="answerVariable"
                    :type-options="ANSWER_TYPES"
                    :known-groups="knownGroups"
                    :owner-node-id="String(props.node.id)"
                    show-storage
                    show-group
                    @update:model-value="onAnswerVariableUpdate"
                />
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.ask-person-button { display: grid; grid-template-columns: 1fr 1fr auto; gap: 6px; align-items: center; }
.ask-person-button-actions { display: flex; gap: 2px; }
</style>
