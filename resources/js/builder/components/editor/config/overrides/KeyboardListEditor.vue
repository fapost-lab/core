<script setup>
import { computed, ref } from 'vue'
import QuickAddButtonsModal from './QuickAddButtonsModal.vue'
import KeyboardLayoutModal from './KeyboardLayoutModal.vue'
import VariablePicker from '../VariablePicker.vue'

const props = defineProps({
    buttons:         { type: Array,   required: true },
    isReplyKeyboard: { type: Boolean, default: false },
    saveToType:      { type: String,  default: 'string' },
})

const emit = defineEmits(['update'])

const UNPLACED = 999

// ── Derive flat list + row sizes ─────────────────────────────────────────────
// Buttons are sorted by (row, order) into a flat sequence. Row boundaries are
// preserved through `rowSizes`, an array of row lengths. The main list shows
// the flat sequence with subtle separators between rows.
const flat = computed(() => {
    const groups = new Map()
    for (const btn of props.buttons) {
        const r = clampRow(btn?.row)
        if (!groups.has(r)) groups.set(r, [])
        groups.get(r).push(btn)
    }
    const sortedKeys = [...groups.keys()].sort((a, b) => a - b)
    const result = []
    sortedKeys.forEach((k, rowIdx) => {
        const sorted = [...groups.get(k)].sort((a, b) => (a.order ?? UNPLACED) - (b.order ?? UNPLACED))
        sorted.forEach((btn) => result.push({ btn, rowIdx }))
    })
    return result
})

const rowSizes = computed(() => {
    const sizes = []
    let current = -1
    for (const { rowIdx } of flat.value) {
        if (rowIdx !== current) { sizes.push(0); current = rowIdx }
        sizes[sizes.length - 1]++
    }
    return sizes
})

function clampRow(v) { return Number.isInteger(v) ? v : 0 }

// ── Mutations ────────────────────────────────────────────────────────────────
function emitFlat(newFlatBtns, sizes) {
    // Re-pack flat list into rows of given sizes, renumber (row, order).
    const out = []
    let i = 0
    sizes.forEach((sz, rowIdx) => {
        for (let o = 0; o < sz && i < newFlatBtns.length; o++, i++) {
            out.push({ ...newFlatBtns[i], row: rowIdx, order: o })
        }
    })
    // If sizes don't cover all buttons (shouldn't happen), append to last row.
    while (i < newFlatBtns.length) {
        const lastRow = sizes.length - 1
        const orderInRow = (sizes[lastRow] ?? 0)
        sizes[lastRow] = orderInRow + 1
        out.push({ ...newFlatBtns[i], row: lastRow, order: orderInRow })
        i++
    }
    emit('update', out)
}

function addButton() {
    const sizes = [...rowSizes.value]
    if (sizes.length === 0) sizes.push(0)
    const lastRow = sizes.length - 1
    sizes[lastRow]++

    const newBtn = {
        id:    crypto.randomUUID(),
        type:  props.isReplyKeyboard ? 'reply' : 'callback',
        label: '',
        value: '',
    }
    const newFlat = [...flat.value.map(({ btn }) => btn), newBtn]
    emitFlat(newFlat, sizes)
}

function updateButton(flatIdx, patch) {
    const newFlat = flat.value.map(({ btn }, i) => (i === flatIdx ? { ...btn, ...patch } : btn))
    emitFlat(newFlat, [...rowSizes.value])
}

function removeButton(flatIdx) {
    const sizes = [...rowSizes.value]
    // Decrement size of the row this button belongs to.
    const rowIdx = flat.value[flatIdx]?.rowIdx ?? 0
    sizes[rowIdx] = Math.max(0, (sizes[rowIdx] ?? 0) - 1)
    // Drop empty trailing rows.
    const cleanedSizes = sizes.filter((s) => s > 0)

    const newFlat = flat.value.filter((_, i) => i !== flatIdx).map(({ btn }) => btn)
    emitFlat(newFlat, cleanedSizes)
}

function applyLayout(updatedButtons) {
    // Layout modal returns buttons with updated row/order. Trust them and pass
    // through to parent — emitFlat would re-normalize and lose intentional
    // empty cells/UNPLACED markers.
    emit('update', updatedButtons)
}

function applyQuickAdd(newButtons) {
    // Append all new chips to the end of the last row.
    const sizes = [...rowSizes.value]
    if (sizes.length === 0) sizes.push(0)
    const lastRow = sizes.length - 1
    sizes[lastRow] += newButtons.length

    const newFlat = [...flat.value.map(({ btn }) => btn), ...newButtons]
    emitFlat(newFlat, sizes)
}

// ── Vertical drag ────────────────────────────────────────────────────────────
// Each row is its own drop target. Mouse Y relative to the row's midpoint
// decides whether the dragged button lands above or below that row. Tiny
// fixed drop slots between rows were unreliable — full-row targets give us
// the entire row height (~36px) to aim at.
const dragIdx = ref(null)
const overIdx = ref(null)
const overPos = ref(null) // 'before' | 'after'

function onDragStart(idx, e) {
    dragIdx.value = idx
    e.dataTransfer.effectAllowed = 'move'
    try { e.dataTransfer.setData('text/plain', String(idx)) } catch { /* Safari quirk */ }
}

function onDragEnd() {
    dragIdx.value = null
    overIdx.value = null
    overPos.value = null
}

function onRowDragOver(idx, e) {
    if (dragIdx.value === null) return
    e.preventDefault()
    const rect = e.currentTarget.getBoundingClientRect()
    const pos = e.clientY < rect.top + rect.height / 2 ? 'before' : 'after'
    if (overIdx.value !== idx || overPos.value !== pos) {
        overIdx.value = idx
        overPos.value = pos
    }
}

function onRowDrop(idx) {
    const src = dragIdx.value
    if (src === null) return onDragEnd()

    const targetFlat = overPos.value === 'after' ? idx + 1 : idx
    if (src === targetFlat || src + 1 === targetFlat) return onDragEnd()

    const items = flat.value.map(({ btn }) => btn)
    const [moved] = items.splice(src, 1)
    let insertAt = targetFlat
    if (src < targetFlat) insertAt = targetFlat - 1
    items.splice(insertAt, 0, moved)
    emitFlat(items, [...rowSizes.value])
    onDragEnd()
}

function resolveLabel(btn) {
    const lbl = btn?.label
    if (typeof lbl === 'object' && lbl !== null) return Object.values(lbl)[0] ?? ''
    return lbl ?? ''
}

// ── Drop-insert ───────────────────────────────────────────────────────────────
function dropInsert(e, currentValue) {
    e.preventDefault()
    const snippet = e.dataTransfer.getData('text/plain')
    if (!snippet) return null
    const el = e.target
    const at = el.selectionStart ?? currentValue.length
    return currentValue.slice(0, at) + snippet + currentValue.slice(el.selectionEnd ?? at)
}

// ── Validation ───────────────────────────────────────────────────────────────
function checkValueType(value) {
    if (!value || value === '') return null
    if (props.saveToType === 'number' && Number.isNaN(Number(value))) return 'Must be a number'
    if (props.saveToType === 'boolean' && value !== 'true' && value !== 'false') return 'Must be "true" or "false"'
    return null
}

function valuePlaceholder() {
    if (props.saveToType === 'number') return 'e.g. 42'
    if (props.saveToType === 'boolean') return 'true or false'
    return 'Saved to flow state'
}

// ── Modal state ──────────────────────────────────────────────────────────────
const quickAddOpen = ref(false)
const layoutOpen   = ref(false)
</script>

<template>
    <div class="kl">
        <!-- Toolbar -->
        <div class="kl-toolbar">
            <button type="button" class="kl-tool-btn" @click="quickAddOpen = true">
                <span class="kl-tool-icon">+</span> Quick add
            </button>
            <button
                type="button"
                class="kl-tool-btn"
                :disabled="buttons.length === 0"
                @click="layoutOpen = true"
            >
                <span class="kl-tool-icon">⊞</span> Layout
                <span v-if="rowSizes.length > 1" class="kl-rows-badge">{{ rowSizes.length }} rows</span>
            </button>
            <VariablePicker />
        </div>

        <!-- Empty state -->
        <div v-if="flat.length === 0" class="kl-empty">
            <p class="kl-empty-text">No buttons yet</p>
            <button type="button" class="kl-empty-cta" @click="addButton">+ Add button</button>
            <p class="kl-empty-hint">or use <strong>Quick add</strong> to create several at once</p>
        </div>

        <!-- Button list -->
        <div v-else class="kl-list">
            <template v-for="(item, idx) in flat" :key="item.btn.id">
                <!-- Row separator (between consecutive rows) -->
                <div
                    v-if="idx > 0 && item.rowIdx !== flat[idx - 1].rowIdx"
                    class="kl-row-sep"
                >
                    <span class="kl-row-sep-label">Row {{ item.rowIdx + 1 }}</span>
                </div>

                <div
                    class="kl-row"
                    :class="{
                        'kl-row--dragging':    dragIdx === idx,
                        'kl-row--drop-before': dragIdx !== null && dragIdx !== idx && overIdx === idx && overPos === 'before',
                        'kl-row--drop-after':  dragIdx !== null && dragIdx !== idx && overIdx === idx && overPos === 'after',
                    }"
                    @dragover="onRowDragOver(idx, $event)"
                    @drop.prevent="onRowDrop(idx)"
                >
                    <div
                        class="kl-handle"
                        draggable="true"
                        title="Drag to reorder"
                        @dragstart="onDragStart(idx, $event)"
                        @dragend="onDragEnd"
                    >⋮⋮</div>

                    <div class="kl-fields">
                        <input
                            class="field-input kl-input"
                            :value="resolveLabel(item.btn)"
                            placeholder="Label"
                            @input="updateButton(idx, { label: $event.target.value })"
                            @drop="e => { const s = dropInsert(e, resolveLabel(item.btn)); if (s !== null) updateButton(idx, { label: s }) }"
                        >
                        <input
                            v-if="!isReplyKeyboard"
                            class="field-input kl-input kl-input--value"
                            :class="{ 'kl-input--invalid': checkValueType(item.btn.value ?? '') }"
                            :value="item.btn.value ?? ''"
                            :placeholder="valuePlaceholder()"
                            @input="updateButton(idx, { value: $event.target.value })"
                        >
                    </div>

                    <button
                        type="button"
                        class="kl-del"
                        title="Remove button"
                        @click="removeButton(idx)"
                    >×</button>
                </div>

            </template>

            <button type="button" class="kl-add-btn" @click="addButton">+ Add button</button>
        </div>

        <!-- Validation summary -->
        <div v-if="!isReplyKeyboard" class="kl-errors">
            <template v-for="(item, idx) in flat" :key="item.btn.id">
                <div v-if="checkValueType(item.btn.value ?? '')" class="kl-error">
                    Button {{ idx + 1 }}: {{ checkValueType(item.btn.value ?? '') }}
                </div>
            </template>
        </div>

        <!-- Modals -->
        <QuickAddButtonsModal
            :open="quickAddOpen"
            :is-reply-keyboard="isReplyKeyboard"
            @close="quickAddOpen = false"
            @confirm="applyQuickAdd"
        />
        <KeyboardLayoutModal
            :open="layoutOpen"
            :buttons="buttons"
            @close="layoutOpen = false"
            @update="applyLayout"
        />
    </div>
</template>

<style scoped>
.kl {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Toolbar */
.kl-toolbar {
    display: flex;
    gap: 6px;
}
.kl-tool-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    color: var(--text-2);
    cursor: pointer;
    transition: border-color .12s, color .12s, background .12s;
}
.kl-tool-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
}
.kl-tool-btn:disabled { opacity: .4; cursor: not-allowed; }

.kl-tool-icon { font-size: 14px; line-height: 1; }
.kl-rows-badge {
    font-size: 10px;
    font-weight: 600;
    color: var(--primary);
    background: var(--primary-bg);
    border-radius: 10px;
    padding: 1px 6px;
    margin-left: 2px;
}

/* Empty state */
.kl-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 18px 12px;
    border: 1.5px dashed var(--border);
    border-radius: 8px;
    background: var(--surface);
}
.kl-empty-text { margin: 0; font-size: 13px; color: var(--text-2); }
.kl-empty-cta {
    padding: 6px 14px;
    border: 1px solid var(--primary);
    border-radius: 6px;
    background: var(--primary);
    color: #fff;
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    font-weight: 500;
    cursor: pointer;
    transition: opacity .12s;
}
.kl-empty-cta:hover { opacity: .9; }
.kl-empty-hint { margin: 0; font-size: 11px; color: var(--text-3); }
.kl-empty-hint strong { color: var(--text-2); }

/* List */
.kl-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.kl-row {
    position: relative;
    display: flex;
    align-items: stretch;
    gap: 6px;
    padding: 5px 6px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--surface);
    transition: border-color .12s, opacity .12s;
}
.kl-row:hover { border-color: var(--border-2); }
.kl-row--dragging { opacity: .35; }

/* Drop indicators — render as 3px line above/below the hovered row. */
.kl-row--drop-before::before,
.kl-row--drop-after::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--primary);
    border-radius: 2px;
    pointer-events: none;
}
.kl-row--drop-before::before { top: -5px; }
.kl-row--drop-after::after   { bottom: -5px; }

.kl-handle {
    width: 16px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
    font-size: 12px;
    cursor: grab;
    user-select: none;
    border-radius: 4px;
    letter-spacing: -2px;
}
.kl-handle:hover { color: var(--text-2); background: var(--surface-2); }
.kl-handle:active { cursor: grabbing; }

.kl-fields {
    flex: 1;
    display: flex;
    gap: 4px;
    min-width: 0;
}

.kl-input {
    flex: 1;
    padding: 5px 8px !important;
    font-size: 12px !important;
    min-width: 0;
}
.kl-input--value {
    flex: 0 1 40%;
    color: var(--text-2);
}
.kl-input--invalid { border-color: #e53e3e !important; }

.kl-del {
    width: 22px;
    flex-shrink: 0;
    border: none;
    background: transparent;
    color: var(--text-3);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    transition: color .12s, background .12s;
}
.kl-del:hover { color: #e53e3e; background: var(--surface-2); }

/* Row separator */
.kl-row-sep {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 4px 0;
}
.kl-row-sep::before,
.kl-row-sep::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}
.kl-row-sep-label {
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-3);
}

/* Add button */
.kl-add-btn {
    align-self: stretch;
    margin-top: 4px;
    padding: 7px 10px;
    border: 1.5px dashed var(--border-2);
    border-radius: 6px;
    background: transparent;
    color: var(--text-3);
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    cursor: pointer;
    transition: border-color .12s, color .12s, background .12s;
}
.kl-add-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
}

/* Validation */
.kl-errors {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.kl-error {
    font-size: 10.5px;
    color: #e53e3e;
}
</style>
