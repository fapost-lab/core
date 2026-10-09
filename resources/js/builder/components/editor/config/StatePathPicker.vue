<script setup lang="ts">
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'
import {useFlowVariables} from '@builder/composables/useFlowVariables'

/**
 * Picker for a state path. Two render modes:
 *   • Legacy `<select>` for the default flow-only scope with optional
 *     "Type manually…" fallback — keeps state pickers in non-strict nodes
 *     working unchanged.
 *   • Strict popover with a search input for {scope:'user', allowManual:false}
 *     — used by the SendMessage dynamic-keyboard Source picker so authors can
 *     find a variable by name in long lists without seeing a noisy source
 *     suffix.
 */

const props = withDefaults(defineProps<{
    modelValue:   string
    placeholder?: string
    /** When false, hides the "Type manually…" fallback — picker is strict. */
    allowManual?: boolean
    /**
     * Namespaces to include in suggestions. Path is matched against the leading
     * segment (e.g. `'flow'` matches `flow.x`, `flow.group.y`). Empty list = all
     * registered user variables, regardless of namespace.
     *
     * Default keeps back-compat with the legacy state pickers — flow-only.
     */
    namespaces?: string[]
}>(), { allowManual: true, namespaces: () => ['flow'] })

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
}>()

const MANUAL = '__manual__'

const {userVars} = useFlowVariables()

const flowPaths = computed(() => {
    const allowed = props.namespaces
    const matches = (path: string): boolean => {
        if (allowed.length === 0) return true
        return allowed.some((ns) => path.startsWith(`${ns}.`))
    }
    return userVars.value
        .filter((v) => matches(v.path))
        .map((v) => ({path: v.path, label: v.label}))
})

const hasOptions = computed(() => flowPaths.value.length > 0)

const isKnown = computed(() =>
    props.modelValue !== '' && flowPaths.value.some((p) => p.path === props.modelValue),
)

// Strict mode flag: when allowManual=false the picker renders the
// search-enabled popover instead of a native <select>.
const strict = computed(() => !props.allowManual)

// ── Legacy <select> mode ─────────────────────────────────────────────────────

const selectVal = computed(() => {
    if (!hasOptions.value) return MANUAL
    if (props.modelValue === '') return ''
    return isKnown.value ? props.modelValue : MANUAL
})

const showInput = computed(() =>
    !strict.value && props.allowManual && (!hasOptions.value || selectVal.value === MANUAL),
)

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
        emit('update:modelValue', manualText.value)
    } else {
        emit('update:modelValue', val)
    }
}

function onManualInput(val: string) {
    manualText.value = val
    emit('update:modelValue', val)
}

// ── Strict popover mode ──────────────────────────────────────────────────────

const open       = ref(false)
const search     = ref('')
const triggerRef = ref<HTMLButtonElement | null>(null)
const menuRef    = ref<HTMLElement | null>(null)
const searchRef  = ref<HTMLInputElement | null>(null)

interface MenuPos { top: number; left: number; width: number }
const menuPos = ref<MenuPos>({ top: 0, left: 0, width: 0 })

const filteredPaths = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (q === '') return flowPaths.value
    return flowPaths.value.filter((p) => p.path.toLowerCase().includes(q))
})

function reposition() {
    const el = triggerRef.value
    if (!el) return
    const rect = el.getBoundingClientRect()
    menuPos.value = { top: rect.bottom + 4, left: rect.left, width: rect.width }
}

async function toggle() {
    if (open.value) {
        close()
        return
    }
    reposition()
    open.value = true
    await nextTick()
    searchRef.value?.focus()
}

function close() {
    open.value = false
    search.value = ''
}

function pick(path: string) {
    emit('update:modelValue', path)
    close()
}

function onDocClick(e: MouseEvent) {
    const target = e.target as Node
    if (triggerRef.value?.contains(target)) return
    if (menuRef.value?.contains(target)) return
    close()
}

function onReposition() { if (open.value) reposition() }

watch(open, (val) => {
    if (val) {
        document.addEventListener('mousedown', onDocClick, { capture: true })
        window.addEventListener('scroll', onReposition, true)
        window.addEventListener('resize', onReposition)
    } else {
        document.removeEventListener('mousedown', onDocClick, { capture: true })
        window.removeEventListener('scroll', onReposition, true)
        window.removeEventListener('resize', onReposition)
    }
})

onUnmounted(() => {
    document.removeEventListener('mousedown', onDocClick, { capture: true })
    window.removeEventListener('scroll', onReposition, true)
    window.removeEventListener('resize', onReposition)
})
</script>

<template>
    <div class="state-path-picker">
        <!-- Strict popover with search -->
        <template v-if="strict">
            <button
                ref="triggerRef"
                type="button"
                class="field-input spp-trigger"
                :class="{ 'spp-trigger--placeholder': modelValue === '', 'spp-trigger--open': open }"
                :aria-expanded="open"
                @click="toggle"
            >
                <span class="spp-trigger-label">{{ modelValue || (placeholder ?? 'Select a variable…') }}</span>
                <span class="spp-trigger-caret" :class="{ 'spp-trigger-caret--open': open }">▾</span>
            </button>

            <Teleport to="body">
                <div
                    v-if="open"
                    ref="menuRef"
                    :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px`, width: `${menuPos.width}px` }"
                    class="spp-menu"
                    role="listbox"
                >
                    <input
                        ref="searchRef"
                        v-model="search"
                        type="text"
                        class="spp-search"
                        placeholder="Search…"
                        autocomplete="off"
                        spellcheck="false"
                    >
                    <div class="spp-options">
                        <button
                            v-for="p in filteredPaths"
                            :key="p.path"
                            type="button"
                            class="spp-option"
                            :class="{ 'spp-option--active': p.path === modelValue }"
                            role="option"
                            :aria-selected="p.path === modelValue"
                            @click="pick(p.path)"
                        >
                            <span class="spp-option-mark">{{ p.path === modelValue ? '✓' : '' }}</span>
                            <span class="spp-option-label">{{ p.path }}</span>
                        </button>
                        <div v-if="filteredPaths.length === 0" class="spp-empty">No matches</div>
                    </div>
                </div>
            </Teleport>

            <p v-if="!hasOptions" class="no-vars-hint">
                No variables found. Add an upstream node (Call / Assign / Input) that saves one.
            </p>
        </template>

        <!-- Legacy <select> + manual input -->
        <template v-else>
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
                >{{ p.path }}</option>
                <template v-if="allowManual">
                    <option disabled>──────────────</option>
                    <option :value="MANUAL">Type manually…</option>
                </template>
            </select>

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
        </template>
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

.spp-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    text-align: left;
    cursor: pointer;
}
.spp-trigger--placeholder .spp-trigger-label {
    color: var(--text-3);
}
.spp-trigger-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.spp-trigger-caret {
    color: var(--text-3);
    font-size: 11px;
    transition: transform 120ms;
    flex-shrink: 0;
}
.spp-trigger-caret--open {
    transform: rotate(180deg);
}

.spp-menu {
    position: fixed;
    z-index: 60;
    display: flex;
    flex-direction: column;
    padding: 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-pop);
    max-height: 280px;
}
.spp-search {
    width: 100%;
    padding: 6px 8px;
    margin-bottom: 4px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface-2);
    color: var(--text);
    font-size: 12.5px;
    font-family: inherit;
    box-sizing: border-box;
}
.spp-search:focus {
    outline: none;
    border-color: var(--primary);
}
.spp-options {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.spp-option {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 8px;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition: background 100ms;
}
.spp-option:hover {
    background: var(--surface-2);
}
.spp-option--active {
    background: var(--primary-bg);
}
.spp-option-mark {
    flex: 0 0 14px;
    text-align: center;
    color: var(--primary);
    font-size: 11px;
    line-height: 1;
}
.spp-option-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.spp-empty {
    padding: 8px;
    font-size: 11px;
    color: var(--text-3);
    text-align: center;
}
</style>
