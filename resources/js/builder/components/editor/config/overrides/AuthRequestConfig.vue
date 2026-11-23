<script setup lang="ts">
/**
 * auth_request node config — a single "authenticate when <operand> <op> <value>"
 * rule, reusing the Branch operand picker and operator vocabulary. On a match
 * the contact is flagged authenticated and the flow takes the `authenticated`
 * output; otherwise `failed`. Version 1 only knows the `basic` method (written
 * implicitly, no selector). Version 2 adds a method selector with the phone and
 * one-time-code methods, each with its own fields.
 */
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import ConditionOperandPicker from './ConditionOperandPicker.vue'
import {type BranchOperandUiState, compileLeft, decompileLeft} from '@builder/utils/branchOperandCompiler'
import {useFlowVariables} from '@builder/composables/useFlowVariables'
import {useTranslations} from '@builder/composables/useTranslations'
import {useBuilderStore} from '@builder/store/builderStore'

const props = defineProps<{
    node:   { id: string; version?: number; config?: Record<string, unknown> }
    schema: Record<string, unknown>
}>()

const emit = defineEmits<{
    (e: 'update:config', patch: Record<string, unknown>): void
}>()

const {t} = useTranslations()
const builderStore = useBuilderStore()
const {userVars} = useFlowVariables()

// Full operator vocabulary, mirrored from BranchOperator. Kept self-sufficient
// (not dependent on the schema payload) so every operator is always offered;
// labels are localized via builder.operators.*.
const OPERATOR_VALUES = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'in', 'empty', 'not_empty'] as const

const UNARY_OPS = new Set(['empty', 'not_empty'])

const config = computed<Record<string, unknown>>(() => props.node.config ?? {})

const operandState = computed<BranchOperandUiState>(() =>
    decompileLeft((config.value.left ?? config.value.variable ?? null) as never, userVars.value),
)

const operator = computed<string>(() =>
    typeof config.value.operator === 'string' ? config.value.operator : 'eq',
)

const valueString = computed<string>(() => {
    const v = config.value.value
    if (v === undefined || v === null) {
        return ''
    }
    return typeof v === 'string' ? v : String(v)
})

const operatorOptions = computed<Array<{ value: string; label: string }>>(() =>
    OPERATOR_VALUES.map((value) => ({value, label: t(`operators.${value}`)})),
)

const isUnary = computed<boolean>(() => UNARY_OPS.has(operator.value))

const METHODS = ['basic', 'phone', 'sms_code', 'email_code'] as const

type BoundedField = 'code_length' | 'ttl_minutes' | 'max_attempts' | 'max_sends' | 'resend_cooldown_seconds'

const CODE_LIMITS = ['code_length', 'ttl_minutes', 'max_attempts', 'max_sends', 'resend_cooldown_seconds'] as const

/**
 * A limit's min, max or default as the handler's config schema declares it (`AuthRequestV2NodeHandler::BOUNDS`
 * through each NumberField), so the builder never offers a range publish refuses. Undefined when the schema does
 * not say: `max_attempts` has no default there, since the phone method and the code methods default differently.
 */
function bound(key: BoundedField, attribute: 'min' | 'max' | 'default'): number | undefined {
    const field = props.schema?.[key]
    const value = field !== null && typeof field === 'object' ? (field as Record<string, unknown>)[attribute] : undefined

    return typeof value === 'number' ? value : undefined
}

function boundPlaceholder(key: BoundedField): string {
    const value = bound(key, 'default')

    return value === undefined ? '' : String(value)
}

const isV2 = computed<boolean>(() => props.node.version === 2)

const method = computed<string>(() => {
    const m = config.value.method
    return typeof m === 'string' && (METHODS as readonly string[]).includes(m) ? m : 'basic'
})

const isBasic = computed<boolean>(() => !isV2.value || method.value === 'basic')
const isPhone = computed<boolean>(() => isV2.value && method.value === 'phone')
const isCode = computed<boolean>(() => isV2.value && (method.value === 'sms_code' || method.value === 'email_code'))

const baseLanguage = computed(() => builderStore.contentBaseLanguage)

function localizedValue(raw: unknown): string {
    if (raw == null) {
        return ''
    }
    if (typeof raw === 'string') {
        return raw
    }
    if (typeof raw === 'object') {
        const map = raw as Record<string, unknown>
        return String(map[baseLanguage.value] ?? Object.values(map)[0] ?? '')
    }
    return String(raw)
}

function onLocalized(key: string, e: Event) {
    const value = (e.target as HTMLInputElement | HTMLTextAreaElement).value
    const existing = config.value[key]
    const base = existing && typeof existing === 'object' ? existing as Record<string, unknown> : {}
    emit('update:config', {[key]: {...base, [baseLanguage.value]: value}})
}

function onText(key: string, e: Event) {
    emit('update:config', {[key]: (e.target as HTMLInputElement).value})
}

function numberValue(key: BoundedField): number | string {
    const v = config.value[key]
    return typeof v === 'number' ? v : ''
}

function onNumber(key: BoundedField, e: Event) {
    const raw = (e.target as HTMLInputElement).value
    emit('update:config', {[key]: raw === '' ? null : Number(raw)})
}

function onMethod(e: Event) {
    emit('update:config', {method: (e.target as HTMLSelectElement).value})
}

// v1 always (re)asserts method='basic' so its config is complete even though it
// has no "method" control; v2 patches carry the author's chosen method.
function patch(p: Record<string, unknown>) {
    // Every patch carries the method, so a node whose author kept the default (basic) still stores it.
    emit('update:config', {method: isV2.value ? method.value : 'basic', ...p})
}

function onOperand(state: BranchOperandUiState) {
    patch({left: compileLeft(state)})
}

function onOperator(e: Event) {
    patch({operator: (e.target as HTMLSelectElement).value})
}

function onValue(e: Event) {
    patch({value: (e.target as HTMLInputElement).value})
}
</script>

<template>
    <div class="accordion">
        <AccordionSection :title="t('nodes.auth_request.section')" default-open>
            <div v-if="isV2" class="config-field">
                <div class="field-label">{{ t('nodes.auth_request.method') }}</div>
                <select class="field-input" :value="method" @change="onMethod">
                    <option v-for="m in METHODS" :key="m" :value="m">{{ t(`nodes.auth_request.methods.${m}`) }}</option>
                </select>
            </div>

            <template v-if="isBasic">
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.condition_label') }}</div>
                    <ConditionOperandPicker
                        :model-value="operandState"
                        @update:model-value="onOperand"
                    />
                </div>

                <div class="cond-row">
                    <select class="field-input op-sel" :value="operator" @change="onOperator">
                        <option v-for="op in operatorOptions" :key="op.value" :value="op.value">{{ op.label }}</option>
                    </select>
                    <input
                        v-if="!isUnary"
                        type="text"
                        class="field-input val-input"
                        placeholder="1234"
                        :value="valueString"
                        @input="onValue"
                    >
                </div>
            </template>

            <template v-if="isPhone">
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.prompt') }}</div>
                    <textarea
                        class="field-input"
                        rows="3"
                        :value="localizedValue(config.prompt)"
                        @input="onLocalized('prompt', $event)"
                    />
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.button_label') }}</div>
                    <input
                        class="field-input"
                        :value="localizedValue(config.button_label)"
                        @input="onLocalized('button_label', $event)"
                    >
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.expected_phone') }}</div>
                    <input
                        class="field-input"
                        :placeholder="'{{contact.phone}}'"
                        :value="typeof config.expected_phone === 'string' ? config.expected_phone : ''"
                        @input="onText('expected_phone', $event)"
                    >
                    <p class="auth-hint">{{ t('nodes.auth_request.expected_phone_hint') }}</p>
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.max_attempts') }}</div>
                    <input
                        type="number"
                        class="field-input num-input"
                        data-testid="phone-max-attempts"
                        :min="bound('max_attempts', 'min')"
                        :max="bound('max_attempts', 'max')"
                        :placeholder="'3'"
                        :value="numberValue('max_attempts')"
                        @input="onNumber('max_attempts', $event)"
                    >
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.on_invalid_message') }}</div>
                    <input
                        class="field-input"
                        :value="localizedValue(config.on_invalid_message)"
                        @input="onLocalized('on_invalid_message', $event)"
                    >
                </div>
            </template>

            <template v-if="isCode">
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.destination') }}</div>
                    <input
                        class="field-input"
                        :placeholder="method === 'sms_code' ? '{{contact.phone}}' : '{{contact.email}}'"
                        :value="typeof config.destination === 'string' ? config.destination : ''"
                        @input="onText('destination', $event)"
                    >
                    <p class="auth-hint">
                        {{ t(method === 'sms_code' ? 'nodes.auth_request.destination_hint_sms' : 'nodes.auth_request.destination_hint_email') }}
                    </p>
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.prompt') }}</div>
                    <textarea
                        class="field-input"
                        rows="3"
                        :value="localizedValue(config.prompt)"
                        @input="onLocalized('prompt', $event)"
                    />
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.resend_label') }}</div>
                    <input
                        class="field-input"
                        :value="localizedValue(config.resend_label)"
                        @input="onLocalized('resend_label', $event)"
                    >
                </div>
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.auth_request.on_invalid_message') }}</div>
                    <input
                        class="field-input"
                        :value="localizedValue(config.on_invalid_message)"
                        @input="onLocalized('on_invalid_message', $event)"
                    >
                </div>
                <div v-for="key in CODE_LIMITS" :key="key" class="config-field">
                    <div class="field-label">{{ t(`nodes.auth_request.${key}`) }}</div>
                    <input
                        type="number"
                        class="field-input num-input"
                        :data-testid="`limit-${key}`"
                        :min="bound(key, 'min')"
                        :max="bound(key, 'max')"
                        :placeholder="boundPlaceholder(key)"
                        :value="numberValue(key)"
                        @input="onNumber(key, $event)"
                    >
                </div>
            </template>

            <p class="auth-hint">{{ t(isV2 ? 'nodes.auth_request.flag_hint_v2' : 'nodes.auth_request.flag_hint') }}</p>
            <p v-if="!isV2" class="auth-hint">{{ t('nodes.auth_request.legacy_hint') }}</p>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:var(--font-mono);font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.config-field {
    margin-bottom: 10px;
}
.field-label {
    font-size: 12px;
    color: var(--text-2);
    margin-bottom: 4px;
}
.cond-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}
.field-input {
    padding: 6px 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-size: 13px;
    color: var(--text);
    outline: none;
}
.field-input:focus {
    border-color: var(--primary);
}
.num-input {
    width: 110px;
}
.op-sel {
    flex: 0 0 170px;
}
.val-input {
    flex: 1;
    min-width: 0;
}
.auth-hint {
    margin: 4px 0 0;
    font-size: 11.5px;
    color: var(--text-3);
    line-height: 1.4;
}
</style>
