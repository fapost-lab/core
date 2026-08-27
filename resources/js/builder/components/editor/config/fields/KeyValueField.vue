<script lang="ts" setup>
import {computed, ref, watch} from 'vue'

/**
 * Editor for `Record<string, string>`-shaped config (HTTP headers,
 * URL params, key/value tags). Keys with empty names are dropped on
 * commit; duplicate keys are flagged inline but still persisted so
 * the author can fix them without losing values.
 */
const props = defineProps({
    value: {type: Object as () => Record<string, unknown> | null | undefined, default: () => ({})},
    schema: {type: Object as () => Record<string, unknown>, default: () => ({})},
})

const emit = defineEmits(['update:value'])

interface Row {
    key: string
    value: string
}

// Local mutable copy so the user can have an empty row in-progress
// without committing it (we only emit rows with a non-empty key).
const rows = ref<Row[]>(toRows(props.value))

watch(() => props.value, (next) => {
    const incoming = toRows(next)
    if (rowsEqual(incoming, rows.value)) return
    rows.value = incoming
}, {deep: true})

const keyLabel = computed(() => String(props.schema.key_label ?? 'Key'))
const valueLabel = computed(() => String(props.schema.value_label ?? 'Value'))
const placeholderPair = computed<Row | null>(() => {
    const ph = props.schema.placeholder
    if (!ph || typeof ph !== 'object') return null
    const entries = Object.entries(ph as Record<string, unknown>)
    if (entries.length === 0) return null
    return {key: String(entries[0][0]), value: String(entries[0][1])}
})

const duplicates = computed<Set<string>>(() => {
    const seen = new Map<string, number>()
    for (const row of rows.value) {
        if (row.key === '') continue
        seen.set(row.key, (seen.get(row.key) ?? 0) + 1)
    }
    const result = new Set<string>()
    for (const [key, count] of seen) {
        if (count > 1) result.add(key)
    }
    return result
})

function toRows(input: Record<string, unknown> | null | undefined): Row[] {
    if (!input || typeof input !== 'object') return []
    return Object.entries(input).map(([key, value]) => ({
        key: String(key),
        value: value == null ? '' : String(value),
    }))
}

function rowsEqual(a: Row[], b: Row[]): boolean {
    if (a.length !== b.length) return false
    for (let i = 0; i < a.length; i++) {
        if (a[i].key !== b[i].key || a[i].value !== b[i].value) return false
    }
    return true
}

function emitCommitted() {
    const obj: Record<string, string> = {}
    for (const row of rows.value) {
        if (row.key === '') continue
        // Last-write-wins on duplicates so the order in the editor matches
        // the persisted map. Validation badge stays visible until the
        // author resolves the clash.
        obj[row.key] = row.value
    }
    emit('update:value', obj)
}

function updateKey(index: number, newKey: string) {
    rows.value[index] = {...rows.value[index], key: newKey}
    emitCommitted()
}

function updateValue(index: number, newValue: string) {
    rows.value[index] = {...rows.value[index], value: newValue}
    emitCommitted()
}

function addRow() {
    rows.value = [...rows.value, {key: '', value: ''}]
}

function removeRow(index: number) {
    rows.value = rows.value.filter((_, i) => i !== index)
    emitCommitted()
}
</script>

<template>
    <div class="kv-field">
        <div class="kv-head">
            <span class="kv-head-key">{{ keyLabel }}</span>
            <span class="kv-head-val">{{ valueLabel }}</span>
            <span class="kv-head-spacer"/>
        </div>
        <div
            v-for="(row, index) in rows"
            :key="index"
            :class="{ 'kv-row--dup': row.key !== '' && duplicates.has(row.key) }"
            class="kv-row"
        >
            <input
                :placeholder="placeholderPair?.key ?? keyLabel"
                :value="row.key"
                class="field-input"
                @input="updateKey(index, ($event.target as HTMLInputElement).value)"
            >
            <input
                :placeholder="placeholderPair?.value ?? valueLabel"
                :value="row.value"
                class="field-input"
                @input="updateValue(index, ($event.target as HTMLInputElement).value)"
            >
            <button
                class="kv-del"
                title="Remove"
                type="button"
                @click="removeRow(index)"
            >×
            </button>
        </div>
        <button
            class="add-item-btn"
            type="button"
            @click="addRow"
        >+ Add {{ keyLabel.toLowerCase() }}
        </button>
        <div v-if="duplicates.size > 0" class="kv-warn">
            Duplicate {{ duplicates.size === 1 ? 'key' : 'keys' }}:
            {{ Array.from(duplicates).join(', ') }}
        </div>
    </div>
</template>

<style scoped>
.kv-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.kv-head {
    display: grid;
    grid-template-columns: 1fr 1fr 22px;
    gap: 6px;
    font-size: 10.5px;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 2px;
}

.kv-row {
    display: grid;
    grid-template-columns: 1fr 1fr 22px;
    gap: 6px;
    align-items: center;
}

.kv-row--dup .field-input {
    border-color: var(--rose);
}

.kv-del {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border: none;
    background: transparent;
    color: var(--text-3);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    transition: color .12s, background .12s;
}

.kv-del:hover {
    color: var(--rose);
    background: var(--rose-bg);
}

.kv-warn {
    font-size: 11px;
    color: var(--rose);
}
</style>
