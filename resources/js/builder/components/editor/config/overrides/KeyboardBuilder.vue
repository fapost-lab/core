<script setup lang="ts">
import {computed, ref} from 'vue'

interface KbButton {
    id: string
    label?: unknown
    row?: number
    order?: number
    [key: string]: unknown
}

const props = defineProps({
    buttons: { type: Array as () => KbButton[], required: true },
})

const emit = defineEmits(['update'])

const UNPLACED = 999
const MIN_DIM  = 1
const MAX_DIM  = 10

// Initialise from existing button positions
function initDim(axis: string): number {
    const vals = props.buttons.map((b: KbButton) => axis === 'col' ? (b.order ?? 0) : (b.row ?? 0))
        .filter((n: number) => n < UNPLACED)
    return Math.min(MAX_DIM, Math.max(MIN_DIM, vals.length > 0 ? Math.max(...vals) + 1 : axis === 'col' ? 2 : 1))
}

const cols = ref(initDim('col'))
const rows = ref(initDim('row'))

function resolveLabel(btn: KbButton): string {
    const lbl = btn?.label
    if (!lbl) return '…'
    return typeof lbl === 'object' ? (Object.values(lbl as Record<string, unknown>)[0] as string || '…') : String(lbl) || '…'
}

const btnMap = computed((): Record<string, KbButton> => Object.fromEntries(props.buttons.map((b: KbButton) => [b.id, b])))

const grid = computed(() => {
    const g: (string | null)[][] = Array.from({ length: rows.value }, () => Array(cols.value).fill(null))
    for (const btn of props.buttons) {
        const r = btn.row ?? 0
        const c = btn.order ?? 0
        if (r < rows.value && c < cols.value && g[r][c] === null) {
            g[r][c] = btn.id
        }
    }
    return g
})

const unplaced = computed((): KbButton[] =>
    props.buttons.filter((b: KbButton) => (b.order ?? UNPLACED) >= cols.value || (b.row ?? 0) >= rows.value),
)

// ── Dim controls ─────────────────────────────────────────────────────────────
function ejectOutOfBounds(nextCols: number, nextRows: number) {
    const next = props.buttons.map((b: KbButton) => {
        const c = b.order ?? UNPLACED
        const r = b.row ?? 0
        if (c >= nextCols || r >= nextRows) return { ...b, order: UNPLACED }
        return { ...b }
    })
    if (next.some((b: KbButton, i: number) => b.order !== props.buttons[i].order)) {
        emit('update', next)
    }
}

function setCols(delta: number) {
    const next = Math.min(MAX_DIM, Math.max(MIN_DIM, cols.value + delta))
    if (next === cols.value) return
    cols.value = next
    ejectOutOfBounds(next, rows.value)
}

function setRows(delta: number) {
    const next = Math.min(MAX_DIM, Math.max(MIN_DIM, rows.value + delta))
    if (next === rows.value) return
    rows.value = next
    ejectOutOfBounds(cols.value, next)
}

// ── Drag & drop ──────────────────────────────────────────────────────────────
const dragId  = ref<string | null>(null)
const overKey = ref<string | null>(null)

function onDragStart(id: string, e: DragEvent) {
    dragId.value = id
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
}
function onDragEnd() { dragId.value = null; overKey.value = null }

function onDragOverCell(e: DragEvent, r: number, c: number) {
    e.preventDefault()
    overKey.value = `${r}-${c}`
}
function onDragOverUnplaced(e: DragEvent) { e.preventDefault(); overKey.value = 'unplaced' }
function onDragLeave(key: string)       { if (overKey.value === key) overKey.value = null }

function isCellOver(r: number, c: number) { return overKey.value === `${r}-${c}` }
function isUnplacedOver()  { return overKey.value === 'unplaced' }

function onDropCell(r: number, c: number) {
    const srcId = dragId.value
    if (!srcId) return

    const occupantId = grid.value[r]?.[c] ?? null
    if (srcId === occupantId) { onDragEnd(); return }

    const next = props.buttons.map((b: KbButton) => ({ ...b }))
    const src  = next.find((b: KbButton) => b.id === srcId)
    if (!src) { onDragEnd(); return }

    if (occupantId) {
        const occ = next.find((b: KbButton) => b.id === occupantId)
        if (occ) { occ.row = src.row ?? 0; occ.order = src.order ?? UNPLACED }
    }

    src.row   = r
    src.order = c

    emit('update', next)
    onDragEnd()
}

function onDropUnplaced() {
    const srcId = dragId.value
    if (!srcId) return
    emit('update', props.buttons.map((b: KbButton) => b.id === srcId ? { ...b, row: 0, order: UNPLACED } : { ...b }))
    onDragEnd()
}
</script>

<template>
    <div class="kb">
        <!-- Dimension controls -->
        <div class="kb-controls">
            <div class="kb-dim">
                <span class="kb-dim-label">Rows</span>
                <div class="kb-dim-btns">
                    <button type="button" class="kb-dim-btn" :disabled="rows <= 1"  @click="setRows(-1)">−</button>
                    <span class="kb-dim-val">{{ rows }}</span>
                    <button type="button" class="kb-dim-btn" :disabled="rows >= 10" @click="setRows(+1)">+</button>
                </div>
            </div>
            <div class="kb-dim-sep" />
            <div class="kb-dim">
                <span class="kb-dim-label">Columns</span>
                <div class="kb-dim-btns">
                    <button type="button" class="kb-dim-btn" :disabled="cols <= 1"  @click="setCols(-1)">−</button>
                    <span class="kb-dim-val">{{ cols }}</span>
                    <button type="button" class="kb-dim-btn" :disabled="cols >= 10" @click="setCols(+1)">+</button>
                </div>
            </div>
        </div>

        <!-- Grid -->
        <div class="kb-grid" :style="{ gridTemplateColumns: `repeat(${cols}, 1fr)` }">
            <template v-for="(row, rIdx) in grid" :key="rIdx">
                <div
                    v-for="(cellId, cIdx) in row"
                    :key="cIdx"
                    class="kb-cell"
                    :class="{
                        'kb-cell--over':     isCellOver(rIdx, cIdx),
                        'kb-cell--occupied': cellId !== null,
                    }"
                    @dragover="onDragOverCell($event, rIdx, cIdx)"
                    @dragleave="onDragLeave(`${rIdx}-${cIdx}`)"
                    @drop.prevent="onDropCell(rIdx, cIdx)"
                >
                    <div
                        v-if="cellId && btnMap[cellId]"
                        class="kb-chip"
                        draggable="true"
                        @dragstart="onDragStart(cellId, $event)"
                        @dragend="onDragEnd"
                    >
                        {{ resolveLabel(btnMap[cellId]) }}
                    </div>
                </div>
            </template>
        </div>

        <!-- Unplaced zone -->
        <div
            class="kb-unplaced"
            :class="{ 'kb-unplaced--over': isUnplacedOver() }"
            @dragover="onDragOverUnplaced"
            @dragleave="onDragLeave('unplaced')"
            @drop.prevent="onDropUnplaced"
        >
            <template v-if="unplaced.length > 0">
                <span class="kb-unplaced-label">Unplaced</span>
                <div
                    v-for="btn in unplaced"
                    :key="btn.id"
                    class="kb-chip kb-chip--unplaced"
                    draggable="true"
                    @dragstart="onDragStart(btn.id, $event)"
                    @dragend="onDragEnd"
                >
                    {{ resolveLabel(btn) }}
                </div>
            </template>
            <span v-else class="kb-unplaced-empty">All buttons placed</span>
        </div>
    </div>
</template>

<style scoped>
.kb { display: flex; flex-direction: column; gap: 8px; }

/* Controls */
.kb-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.kb-dim {
    display: flex;
    align-items: center;
    gap: 6px;
}
.kb-dim-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-3);
}
.kb-dim-btns { display: flex; align-items: center; gap: 2px; }
.kb-dim-btn {
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
.kb-dim-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.kb-dim-btn:disabled { opacity: .35; cursor: default; }
.kb-dim-val {
    min-width: 20px;
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}
.kb-dim-sep {
    width: 1px; height: 18px;
    background: var(--border);
    flex-shrink: 0;
}

/* Grid */
.kb-grid { display: grid; gap: 4px; }
.kb-cell {
    height: 40px;
    border: 1.5px dashed var(--border-2);
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    transition: border-color .12s, background .12s;
}
.kb-cell--over     { border-color: var(--primary); background: var(--primary-bg, #eef2ee); }
.kb-cell--occupied { border-style: solid; border-color: var(--border); }

/* Chips */
.kb-chip {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    padding: 0 8px;
    font-size: 12.5px;
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    cursor: grab;
    border-radius: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    user-select: none;
}
.kb-chip:active { cursor: grabbing; }
.kb-chip--unplaced {
    width: auto; height: 32px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    padding: 0 10px;
}

/* Unplaced zone */
.kb-unplaced {
    min-height: 36px;
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
    padding: 6px 8px;
    border: 1.5px dashed var(--border);
    border-radius: 7px;
    background: var(--surface-2, #f8f9fa);
    transition: border-color .12s, background .12s;
}
.kb-unplaced--over { border-color: var(--primary); background: var(--primary-bg, #eef2ee); }
.kb-unplaced-label {
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-3); flex-shrink: 0;
}
.kb-unplaced-empty { font-size: 11px; color: var(--text-3); width: 100%; text-align: center; }
</style>
