<script lang="ts" setup>
import {computed, ref, watch} from 'vue'
import type {FieldEntry} from '../SchemaFields.vue'
import SchemaFields from '../SchemaFields.vue'

/**
 * Repeater of structured objects. Each row is rendered as an accordion
 * containing the item-schema's fields via SchemaFields. The accordion
 * label is derived from an optional `item_label` template — placeholder
 * substitution uses {{key}} for top-level item keys so the collapsed
 * row still hints at its content.
 */
const props = defineProps({
    value: {type: Array as () => unknown[] | null | undefined, default: () => []},
    schema: {type: Object as () => Record<string, unknown>, default: () => ({})},
    rootConfig: {type: Object as () => Record<string, unknown>, default: () => ({})},
})

const emit = defineEmits(['update:value'])

const items = computed<Record<string, unknown>[]>(() =>
    Array.isArray(props.value)
        ? (props.value as unknown[]).map((it) =>
            it && typeof it === 'object' ? (it as Record<string, unknown>) : {},
        )
        : [],
)

const itemSchema = computed<Record<string, unknown>>(() =>
    (props.schema.item ?? {}) as Record<string, unknown>,
)

const itemFields = computed<FieldEntry[]>(() => {
    const raw = (itemSchema.value.fields ?? {}) as Record<string, unknown>
    const requiredList = Array.isArray(itemSchema.value.required)
        ? (itemSchema.value.required as string[])
        : []

    const entries: FieldEntry[] = []
    for (const [key, fieldSchemaRaw] of Object.entries(raw)) {
        if (!fieldSchemaRaw || typeof fieldSchemaRaw !== 'object') continue
        const fieldSchema = fieldSchemaRaw as Record<string, unknown>
        entries.push({
            key,
            schema: fieldSchema,
            required: Boolean(fieldSchema.required) || requiredList.includes(key),
        })
    }
    return entries
})

const labelTemplate = computed<string>(() =>
    typeof itemSchema.value.item_label === 'string'
        ? (itemSchema.value.item_label as string)
        : '',
)

const minItems = computed<number>(() => Math.max(0, Number(props.schema.min_items ?? 0)))
const maxItems = computed<number>(() => {
    const raw = Number(props.schema.max_items ?? 0)
    return raw > 0 ? raw : Number.POSITIVE_INFINITY
})

// Track per-row expanded state locally. Adding a new row auto-expands it.
const expanded = ref<boolean[]>(items.value.map(() => false))

watch(items, (next) => {
    if (expanded.value.length === next.length) return
    if (expanded.value.length < next.length) {
        expanded.value = [
            ...expanded.value,
            ...Array(next.length - expanded.value.length).fill(false),
        ]
    } else {
        expanded.value = expanded.value.slice(0, next.length)
    }
}, {deep: true, immediate: true})

function rowLabel(item: Record<string, unknown>, index: number): string {
    if (!labelTemplate.value) return `Item ${index + 1}`
    return labelTemplate.value.replace(/\{(\w+)\}/g, (_, key: string) => {
        const v = item[key]
        if (v == null || v === '') return '—'
        return String(v)
    })
}

function patchItem(index: number, patch: Record<string, unknown>) {
    const next = items.value.map((it, i) => {
        if (i !== index) return it
        const merged = {...it, ...patch}
        for (const [k, v] of Object.entries(patch)) {
            if (v === undefined) delete merged[k]
        }
        return merged
    })
    emit('update:value', next)
}

function addItem() {
    if (items.value.length >= maxItems.value) return
    const fresh: Record<string, unknown> = {}
    for (const field of itemFields.value) {
        if (field.schema.default !== undefined) fresh[field.key] = field.schema.default
    }
    emit('update:value', [...items.value, fresh])
    expanded.value = [...expanded.value, true]
}

function removeItem(index: number) {
    if (items.value.length <= minItems.value) return
    emit('update:value', items.value.filter((_, i) => i !== index))
    expanded.value = expanded.value.filter((_, i) => i !== index)
}

function toggle(index: number) {
    expanded.value = expanded.value.map((open, i) => (i === index ? !open : open))
}
</script>

<template>
    <div class="object-array">
        <div
            v-for="(item, index) in items"
            :key="index"
            :class="{ 'oa-item--open': expanded[index] }"
            class="oa-item"
        >
            <div class="oa-head">
                <button
                    class="oa-toggle"
                    type="button"
                    @click="toggle(index)"
                >
                    <span :class="{ 'oa-chevron--open': expanded[index] }" class="oa-chevron">›</span>
                    <span class="oa-label">{{ rowLabel(item, index) }}</span>
                </button>
                <button
                    :disabled="items.length <= minItems"
                    :title="items.length <= minItems ? `Minimum ${minItems} required` : 'Remove'"
                    class="oa-del"
                    type="button"
                    @click="removeItem(index)"
                >×
                </button>
            </div>
            <div v-show="expanded[index]" class="oa-body">
                <SchemaFields
                    :config="item"
                    :fields="itemFields"
                    :root-config="rootConfig"
                    @update:config="patchItem(index, $event)"
                />
            </div>
        </div>
        <button
            :disabled="items.length >= maxItems"
            class="add-item-btn"
            type="button"
            @click="addItem"
        >+ Add item
        </button>
    </div>
</template>

<style scoped>
.object-array {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.oa-item {
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    overflow: hidden;
}

.oa-head {
    display: flex;
    align-items: center;
    gap: 4px;
}

.oa-toggle {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 6px;
    background: transparent;
    border: none;
    padding: 8px 10px;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
    color: var(--text);
    text-align: left;
}

.oa-toggle:hover {
    color: var(--primary);
}

.oa-chevron {
    font-size: 14px;
    line-height: 1;
    color: var(--text-3);
    transition: transform .15s;
    display: inline-block;
}

.oa-chevron--open {
    transform: rotate(90deg);
}

.oa-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.oa-del {
    width: 28px;
    height: 28px;
    margin-right: 4px;
    border: none;
    background: transparent;
    color: var(--text-3);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    transition: color .12s, background .12s;
}

.oa-del:hover:not(:disabled) {
    color: var(--rose);
    background: var(--rose-bg);
}

.oa-del:disabled {
    opacity: .35;
    cursor: not-allowed;
}

.oa-body {
    padding: 10px 12px 12px;
    background: var(--surface);
    border-top: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
