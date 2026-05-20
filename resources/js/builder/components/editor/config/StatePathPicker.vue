<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useFlowVariables} from '@builder/composables/useFlowVariables'

/**
 * Picker for a session-scoped state path (flow.*).
 * Shows a select of known flow variables from the current graph; falls back
 * to a manual text input when the list is empty or the user chooses to enter
 * a custom path.
 */

const props = withDefaults(defineProps<{
    modelValue:   string
    placeholder?: string
    /** When false, hides the "Type manually…" fallback — picker is strict. */
    allowManual?: boolean
}>(), { allowManual: true })

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
}>()

const MANUAL = '__manual__'

const {userVars} = useFlowVariables()

const flowPaths = computed(() =>
    userVars.value
        .filter((v) => v.path.startsWith('flow.'))
        .map((v) => ({path: v.path, label: v.label, sourceNode: v.sourceNode}))
)

const hasOptions = computed(() => flowPaths.value.length > 0)

const isKnown = computed(() =>
    props.modelValue !== '' && flowPaths.value.some((p) => p.path === props.modelValue)
)

// What the <select> currently shows.
const selectVal = computed(() => {
    if (!hasOptions.value) return MANUAL
    if (props.modelValue === '') return ''
    return isKnown.value ? props.modelValue : MANUAL
})

// Whether the free-text input is visible. Strict mode (allowManual=false)
// never shows the input — the picker becomes a dropdown over known paths.
const showInput = computed(() =>
    props.allowManual && (!hasOptions.value || selectVal.value === MANUAL),
)

// Local ref for the manual input so it retains a custom value when the
// user temporarily switches away and back to "Type manually…".
const manualText = ref(isKnown.value ? '' : props.modelValue)

watch(
    () => props.modelValue,
    (val) => {
        if (!flowPaths.value.some((p) => p.path === val)) {
            manualText.value = val
        }
    },
)

function onSelectChange(val: string) {
    if (val === MANUAL) {
        // Stay on manual; keep emitting the current manualText.
        emit('update:modelValue', manualText.value)
    } else {
        emit('update:modelValue', val)
    }
}

function onManualInput(val: string) {
    manualText.value = val
    emit('update:modelValue', val)
}
</script>

<template>
    <div class="state-path-picker">
        <!-- Known-paths select -->
        <select
            v-if="hasOptions"
            class="field-input"
            :value="selectVal"
            @change="onSelectChange(($event.target as HTMLSelectElement).value)"
        >
            <option value="" disabled>Select a variable…</option>
            <option
                v-for="p in flowPaths"
                :key="p.path"
                :value="p.path"
                :title="p.sourceNode ? `From: ${p.sourceNode}` : undefined"
            >{{ p.path }}<template v-if="p.sourceNode"> · {{ p.sourceNode }}</template>
            </option>
            <template v-if="allowManual">
                <option disabled>──────────────</option>
                <option :value="MANUAL">Type manually…</option>
            </template>
        </select>

        <!-- Free-text input — visible when no known options or manual mode -->
        <input
            v-if="showInput"
            class="field-input"
            :class="{'state-path-picker__input--below': hasOptions}"
            type="text"
            :value="manualText"
            :placeholder="placeholder ?? 'flow.my_collection'"
            @input="onManualInput(($event.target as HTMLInputElement).value)"
        >

        <p v-if="!hasOptions" class="no-vars-hint">
            No flow variables found. Add an Assign or Call node that saves a collection.
        </p>
        <p v-else-if="!allowManual" class="no-vars-hint">
            Pick a variable populated by an upstream node (e.g. Call).
        </p>
    </div>
</template>

<style scoped>
.state-path-picker {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.state-path-picker__input--below {
    margin-top: 0;
}

.no-vars-hint {
    font-size: 10px;
    color: var(--text-3);
    margin: 0;
}
</style>
