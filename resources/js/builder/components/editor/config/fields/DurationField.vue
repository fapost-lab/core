<script setup lang="ts">
/**
 * Duration picker — a number input plus a unit selector. The stored value is
 * an ISO 8601 duration string (e.g. `PT24H`, `P7D`, `P3M`), parseable by PHP's
 * DateInterval. Minutes vs months disambiguate via the `T` separator
 * (`PT5M` = 5 minutes, `P5M` = 5 months).
 */
import {computed, ref, watch} from 'vue'

const props = defineProps({
    value:  { type: String, default: '' },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:value'])

interface UnitDef {
    key:   string
    label: string
    /** Build the ISO 8601 string for `n` of this unit. */
    iso:   (n: number) => string
}

const ALL_UNITS: UnitDef[] = [
    { key: 'minutes', label: 'minutes', iso: (n) => `PT${n}M` },
    { key: 'hours',   label: 'hours',   iso: (n) => `PT${n}H` },
    { key: 'days',    label: 'days',    iso: (n) => `P${n}D` },
    { key: 'weeks',   label: 'weeks',   iso: (n) => `P${n}W` },
    { key: 'months',  label: 'months',  iso: (n) => `P${n}M` },
    { key: 'years',   label: 'years',   iso: (n) => `P${n}Y` },
]

const units = computed<UnitDef[]>(() => {
    const allowed = (props.schema as { units?: unknown }).units
    if (Array.isArray(allowed) && allowed.length > 0) {
        const set = new Set(allowed.map(String))
        const filtered = ALL_UNITS.filter((u) => set.has(u.key))
        return filtered.length > 0 ? filtered : ALL_UNITS
    }
    return ALL_UNITS
})

/** Parse an ISO 8601 duration into { amount, unit }. Falls back gracefully. */
function parseIso(iso: string): { amount: number; unit: string } {
    const fallback = { amount: 1, unit: units.value[0]?.key ?? 'hours' }
    if (typeof iso !== 'string' || iso === '') return fallback

    // Time component (after T): H = hours, M = minutes, S = seconds.
    const timeMatch = iso.match(/T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/)
    if (timeMatch) {
        if (timeMatch[1]) return { amount: Number(timeMatch[1]), unit: 'hours' }
        if (timeMatch[2]) return { amount: Number(timeMatch[2]), unit: 'minutes' }
    }
    // Date component (before T): Y, M (months), W, D.
    const dateMatch = iso.match(/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?/)
    if (dateMatch) {
        if (dateMatch[1]) return { amount: Number(dateMatch[1]), unit: 'years' }
        if (dateMatch[2]) return { amount: Number(dateMatch[2]), unit: 'months' }
        if (dateMatch[3]) return { amount: Number(dateMatch[3]), unit: 'weeks' }
        if (dateMatch[4]) return { amount: Number(dateMatch[4]), unit: 'days' }
    }
    return fallback
}

const parsed = parseIso(props.value)
const amount = ref<number>(parsed.amount)
const unit   = ref<string>(units.value.some((u) => u.key === parsed.unit) ? parsed.unit : (units.value[0]?.key ?? 'hours'))

// Re-sync when switching between nodes (value prop changes externally).
watch(
    () => props.value,
    (next) => {
        const p = parseIso(next)
        amount.value = p.amount
        if (units.value.some((u) => u.key === p.unit)) {
            unit.value = p.unit
        }
    },
)

function emitValue() {
    const n = Math.max(1, Math.floor(Number(amount.value) || 1))
    amount.value = n
    const def = units.value.find((u) => u.key === unit.value) ?? units.value[0]
    if (def) {
        emit('update:value', def.iso(n))
    }
}
</script>

<template>
    <div class="duration-field">
        <input
            class="field-input duration-amount"
            type="number"
            min="1"
            step="1"
            :value="amount"
            @input="amount = Number(($event.target as HTMLInputElement).value)"
            @change="emitValue"
        >
        <select
            class="field-input duration-unit"
            :value="unit"
            @change="unit = ($event.target as HTMLSelectElement).value; emitValue()"
        >
            <option v-for="u in units" :key="u.key" :value="u.key">{{ u.label }}</option>
        </select>
    </div>
</template>

<style scoped>
.duration-field {
    display: flex;
    gap: 8px;
}
.duration-amount {
    width: 90px;
    flex-shrink: 0;
}
.duration-unit {
    flex: 1;
}
</style>
