<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import BaseModal from './BaseModal.vue'

interface KbButton {
    id: string
    label?: unknown
    row?: number
    order?: number
    [key: string]: unknown
}

const props = defineProps({
    open:    { type: Boolean, required: true },
    buttons: { type: Array as () => KbButton[], required: true },
})

const emit = defineEmits(['close', 'update'])

const UNPLACED = 999
const MIN_DIM  = 1
const MAX_DIM  = 10

// Working copy of buttons — committed only on Apply
const draft = ref<KbButton[]>([])
const cols  = ref(1)
const rows  = ref(1)

watch(() => props.open, (val) => {
    if (val) initFromProps()
})

// Initial setup. If existing layout looks "unset" (all in row 0 with sequential
// orders, or all unplaced), default to a single horizontal row of buttons.
function initFromProps() {
    const src: KbButton[] = props.buttons.map((b: KbButton) => ({ ...b }))
    const placed = src.filter((b: KbButton) => (b.order ?? UNPLACED) < UNPLACED)
    const distinctRows = new Set(placed.map((b: KbButton) => b.row ?? 0))
    const isFlat = placed.length === src.length && distinctRows.size <= 1

    if (isFlat || src.length === 0) {
        // Lay everything in row 0, columns = buttons count.
        src.forEach((b: KbButton, idx: number) => { b.row = 0; b.order = idx })
        cols.value = Math.min(MAX_DIM, Math.max(MIN_DIM, src.length || 1))
        rows.value = 1
    } else {
        const maxCol = Math.max(0, ...placed.map((b: KbButton) => b.order ?? 0))
        const maxRow = Math.max(0, ...placed.map((b: KbButton) => b.row ?? 0))
        cols.value = Math.min(MAX_DIM, Math.max(MIN_DIM, maxCol + 1))
        rows.value = Math.min(MAX_DIM, Math.max(MIN_DIM, maxRow + 1))
    }
    draft.value = src
}

// Map: { 'row-col': button-id } for current draft
const grid = computed(() => {
    const g: (string | null)[][] = Array.from({ length: rows.value }, () => Array(cols.value).fill(null))
    for (const btn of draft.value) {
        const r = btn.row ?? 0
        const c = btn.order ?? UNPLACED
        if (r < rows.value && c < cols.value && g[r][c] === null) {
            g[r][c] = btn.id
        }
    }
    return g
})

const unplaced = computed((): KbButton[] =>
    draft.value.filter((b: KbButton) => (b.order ?? UNPLACED) >= cols.value || (b.row ?? 0) >= rows.value),
)

const btnMap = computed((): Record<string, KbButton> => Object.fromEntries(draft.value.map((b: KbButton) => [b.id, b])))

function resolveLabel(btn: KbButton): string {
    const lbl = btn?.label
    if (!lbl) return '…'
    return typeof lbl === 'object' ? (Object.values(lbl as Record<string, unknown>)[0] as string || '…') : (String(lbl) || '…')
}

// Resize controls — buttons that fall out of new bounds become unplaced.
function setCols(delta: number) {
    const next = clamp(cols.value + delta)
    if (next === cols.value) return
    cols.value = next
    ejectOutOfBounds()
}

function setRows(delta: number) {
    const next = clamp(rows.value + delta)
    if (next === rows.value) return
    rows.value = next
    ejectOutOfBounds()
}

function clamp(v: number) { return Math.min(MAX_DIM, Math.max(MIN_DIM, v)) }

function ejectOutOfBounds() {
    draft.value = draft.value.map((b: KbButton) => {
        const c = b.order ?? UNPLACED
        const r = b.row ?? 0
        if (c >= cols.value || r >= rows.value) return { ...b, order: UNPLACED }
        return b
    })
}

// ── Drag & drop ──────────────────────────────────────────────────────────────
const dragId  = ref<string | null>(null)
const overKey = ref<string | null>(null)

function onDragStart(id: string, e: DragEvent) {
    dragId.value = id
    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move'
        try { e.dataTransfer.setData('text/plain', id) } catch { /* Safari quirk */ }
    }
}
function onDragEnd() { dragId.value = null; overKey.value = null }

function onCellDragOver(e: DragEvent, r: number, c: number) { e.preventDefault(); overKey.value = `${r}-${c}` }
function onUnplacedDragOver(e: DragEvent)    { e.preventDefault(); overKey.value = 'unplaced' }
function onDragLeave(key: string)         { if (overKey.value === key) overKey.value = null }

function isCellOver(r: number, c: number) { return overKey.value === `${r}-${c}` }
function isUnplacedOver()  { return overKey.value === 'unplaced' }

function onDropCell(r: number, c: number) {
    const srcId = dragId.value
    if (!srcId) return

    const occupantId = grid.value[r]?.[c] ?? null
    if (srcId === occupantId) return onDragEnd()

    const next = draft.value.map((b: KbButton) => ({ ...b }))
    const src  = next.find((b: KbButton) => b.id === srcId)
    if (!src) return onDragEnd()

    if (occupantId) {
        const occ = next.find((b: KbButton) => b.id === occupantId)
        if (occ) { occ.row = src.row ?? 0; occ.order = src.order ?? UNPLACED }
    }
    src.row = r
    src.order = c

    draft.value = next
    onDragEnd()
}

function onDropUnplaced() {
    const srcId = dragId.value
    if (!srcId) return
    draft.value = draft.value.map((b: KbButton) => b.id === srcId ? { ...b, row: 0, order: UNPLACED } : b)
    onDragEnd()
}

function apply() {
    // Normalize: drop any UNPLACED markers — main panel decides their fate.
    // Rather than dumping them into row 0, we leave them with order=UNPLACED;
    // the parent normalizes them into the last materialized row on save.
    emit('update', draft.value.map((b: KbButton) => ({ ...b })))
    emit('close')
}

function cancel() {
    emit('close')
}
</script>

<template>
    <BaseModal :open="open" title="Keyboard layout" width="900px" @close="cancel">
        <p class="kl-hint">
            Drag buttons into cells to arrange the keyboard.
            Use <strong>Rows</strong> and <strong>Columns</strong> to resize the grid.
            Buttons that don't fit appear in <em>Unplaced</em> at the bottom.
        </p>

        <!-- Dimension controls -->
        <div class="kl-dims">
            <div class="kl-dim">
                <span class="kl-dim-label">Rows</span>
                <button type="button" class="kl-dim-btn" :disabled="rows <= MIN_DIM" @click="setRows(-1)">−</button>
                <span class="kl-dim-val">{{ rows }}</span>
                <button type="button" class="kl-dim-btn" :disabled="rows >= MAX_DIM" @click="setRows(+1)">+</button>
            </div>
            <div class="kl-dim">
                <span class="kl-dim-label">Columns</span>
                <button type="button" class="kl-dim-btn" :disabled="cols <= MIN_DIM" @click="setCols(-1)">−</button>
                <span class="kl-dim-val">{{ cols }}</span>
                <button type="button" class="kl-dim-btn" :disabled="cols >= MAX_DIM" @click="setCols(+1)">+</button>
            </div>
        </div>

        <!-- Grid -->
        <div class="kl-grid-wrap">
            <div class="kl-grid" :style="{ gridTemplateColumns: `repeat(${cols}, minmax(72px, 1fr))` }">
            <template v-for="(row, rIdx) in grid" :key="rIdx">
                <div
                    v-for="(cellId, cIdx) in row"
                    :key="cIdx"
                    class="kl-cell"
                    :class="{ 'kl-cell--over': isCellOver(rIdx, cIdx), 'kl-cell--occupied': cellId !== null }"
                    @dragover="onCellDragOver($event, rIdx, cIdx)"
                    @dragleave="onDragLeave(`${rIdx}-${cIdx}`)"
                    @drop.prevent="onDropCell(rIdx, cIdx)"
                >
                    <div
                        v-if="cellId && btnMap[cellId]"
                        class="kl-chip"
                        draggable="true"
                        @dragstart="onDragStart(cellId, $event)"
                        @dragend="onDragEnd"
                    >{{ resolveLabel(btnMap[cellId]) }}</div>
                </div>
            </template>
            </div>
        </div>

        <!-- Unplaced -->
        <div
            class="kl-unplaced"
            :class="{ 'kl-unplaced--over': isUnplacedOver() }"
            @dragover="onUnplacedDragOver"
            @dragleave="onDragLeave('unplaced')"
            @drop.prevent="onDropUnplaced"
        >
            <span class="kl-unplaced-label">Unplaced</span>
            <template v-if="unplaced.length > 0">
                <div
                    v-for="btn in unplaced"
                    :key="btn.id"
                    class="kl-chip kl-chip--unplaced"
                    draggable="true"
                    @dragstart="onDragStart(btn.id, $event)"
                    @dragend="onDragEnd"
                >{{ resolveLabel(btn) }}</div>
            </template>
            <span v-else class="kl-unplaced-empty">All buttons placed</span>
        </div>

        <template #footer>
            <button type="button" class="kl-btn" @click="cancel">Cancel</button>
            <button type="button" class="kl-btn kl-btn--primary" @click="apply">Apply</button>
        </template>
    </BaseModal>
</template>

<style scoped>
.kl-hint {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--text-2);
    line-height: 1.5;
}

.kl-dims {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
}
.kl-dim {
    display: flex;
    align-items: center;
    gap: 6px;
}
.kl-dim-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-3);
    margin-right: 2px;
}
.kl-dim-btn {
    width: 24px; height: 24px;
    border: 1px solid var(--border);
    border-radius: 5px;
    background: var(--surface);
    font-size: 14px;
    line-height: 1;
    color: var(--text-2);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: border-color .1s, color .1s;
}
.kl-dim-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.kl-dim-btn:disabled { opacity: .35; cursor: default; }
.kl-dim-val {
    min-width: 22px;
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}

.kl-grid-wrap {
    overflow-x: auto;
    margin-bottom: 12px;
}
.kl-grid {
    display: grid;
    gap: 6px;
}
.kl-cell {
    height: 44px;
    border: 1.5px dashed var(--border-2);
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    transition: border-color .12s, background .12s;
}
.kl-cell--over     { border-color: var(--primary); background: var(--primary-bg); }
.kl-cell--occupied { border-style: solid; border-color: var(--border); background: var(--surface-2); }

.kl-chip {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    padding: 0 8px;
    font-size: 12.5px;
    color: var(--text);
    cursor: grab;
    border-radius: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    user-select: none;
}
.kl-chip:active { cursor: grabbing; }
.kl-chip--unplaced {
    width: auto; height: 28px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--surface);
    padding: 0 12px;
}

.kl-unplaced {
    min-height: 48px;
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
    padding: 8px 10px;
    border: 1.5px dashed var(--border);
    border-radius: 7px;
    background: var(--surface-2);
    transition: border-color .12s, background .12s;
}
.kl-unplaced--over { border-color: var(--primary); background: var(--primary-bg); }
.kl-unplaced-label {
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-3); flex-shrink: 0;
}
.kl-unplaced-empty { font-size: 11.5px; color: var(--text-3); flex: 1; text-align: center; }

.kl-btn {
    padding: 6px 14px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-family: var(--font-sans);
    font-size: 12.5px;
    color: var(--text-2);
    cursor: pointer;
    transition: border-color .12s, background .12s, color .12s;
}
.kl-btn:hover { border-color: var(--border-2); color: var(--text); }
.kl-btn--primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
    font-weight: 500;
}
.kl-btn--primary:hover { opacity: .9; }
</style>
