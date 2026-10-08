<script setup lang="ts">
/**
 * Recursive JSON viewer. Every key is clickable and emits its dot-path
 * (relative to the root value passed in), so the Call config can turn a
 * response field into a result_mapping entry. Objects/arrays are collapsible.
 */
import {computed, ref} from 'vue'
import JsonTree from './JsonTree.vue'

const props = defineProps<{
    value: unknown
    path?: string
}>()

const emit = defineEmits<{
    (e: 'select', path: string): void
}>()

interface Entry {
    key:        string
    path:       string
    value:      unknown
    expandable: boolean
    preview:    string
    kind:       'string' | 'number' | 'boolean' | 'null' | 'object' | 'array'
}

function kindOf(v: unknown): Entry['kind'] {
    if (v === null) return 'null'
    if (Array.isArray(v)) return 'array'
    return typeof v as Entry['kind']
}

function preview(v: unknown): string {
    const k = kindOf(v)
    if (k === 'array') return `[${(v as unknown[]).length}]`
    if (k === 'object') return `{${Object.keys(v as object).length}}`
    if (k === 'string') return `"${v as string}"`
    if (k === 'null') return 'null'
    return String(v)
}

const entries = computed<Entry[]>(() => {
    const v = props.value
    if (v === null || typeof v !== 'object') return []
    const base = props.path ?? ''
    return Object.entries(v as Record<string, unknown>).map(([key, val]) => {
        const childPath = base === '' ? key : `${base}.${key}`
        const kind = kindOf(val)
        return {
            key,
            path:       childPath,
            value:      val,
            expandable: kind === 'object' || kind === 'array',
            preview:    preview(val),
            kind,
        }
    })
})

const open = ref<Record<string, boolean>>({})
function toggle(key: string) { open.value[key] = !open.value[key] }
</script>

<template>
    <div class="jt">
        <template v-for="entry in entries" :key="entry.path">
            <div class="jt-row">
                <span
                    v-if="entry.expandable"
                    class="jt-toggle"
                    @click="toggle(entry.key)"
                >{{ open[entry.key] ? '▾' : '▸' }}</span>
                <span v-else class="jt-toggle jt-toggle--leaf" />

                <span class="jt-key" :title="`Use ${entry.path}`" @click="emit('select', entry.path)">{{ entry.key }}</span>
                <span class="jt-colon">:</span>
                <span class="jt-val" :class="`jt-${entry.kind}`">{{ entry.preview }}</span>
            </div>
            <JsonTree
                v-if="entry.expandable && open[entry.key]"
                :value="entry.value"
                :path="entry.path"
                class="jt-child"
                @select="emit('select', $event)"
            />
        </template>
    </div>
</template>

<style scoped>
.jt { font-family: var(--font-mono); font-size: 11.5px; line-height: 1.6; }
.jt-child { margin-left: 14px; border-left: 1px dotted var(--border); padding-left: 6px; }
.jt-row { display: flex; align-items: baseline; gap: 3px; white-space: nowrap; }
.jt-toggle { width: 12px; flex-shrink: 0; cursor: pointer; color: var(--text-3); user-select: none; }
.jt-toggle--leaf { cursor: default; }
.jt-key {
    color: var(--primary, #5b7fa6);
    cursor: pointer;
    border-radius: 3px;
    padding: 0 2px;
}
.jt-key:hover { background: var(--primary-bg, #eef2f7); text-decoration: underline; }
.jt-colon { color: var(--text-3); }
.jt-val { overflow: hidden; text-overflow: ellipsis; }
.jt-string  { color: #7a8c52; }
.jt-number  { color: #b06d3a; }
.jt-boolean { color: #8a5fb0; }
.jt-null    { color: var(--text-3); }
.jt-object,
.jt-array   { color: var(--text-3); }
</style>
