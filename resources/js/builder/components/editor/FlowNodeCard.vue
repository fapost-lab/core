<script setup>
import { computed } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'
import { useRegistryStore } from '@builder/store/registryStore'
import { useBuilderStore } from '@builder/store/builderStore'
import { nodeColors } from '@builder/utils/nodeColors'

const props = defineProps({
    treeNode: { type: Object, required: true },
    index:    { type: Number, default: null },
})

const selectionStore = useSelectionStore()
const registryStore  = useRegistryStore()
const builderStore   = useBuilderStore()

const isSelected = computed(() => selectionStore.selectedNodeId === props.treeNode.node.id)
const hasError   = computed(() => builderStore.nodesWithErrors.has(props.treeNode.node.id))
const colors     = computed(() => nodeColors(props.treeNode.node.type))
const handlerMeta = computed(() => registryStore.getByType(props.treeNode.node.type, props.treeNode.node.version))

const typeLabel = computed(() => {
    if (handlerMeta.value?.label) return handlerMeta.value.label
    return props.treeNode.node.type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
})

/** Compact summary rows for the card body */
const summaryRows = computed(() => {
    const node   = props.treeNode.node
    const config = node.config ?? {}
    const type   = node.type

    if (type === 'send_message') {
        const rows = []
        const text = config.text ?? config.caption ?? config.media_url ?? config.body ?? config.content_key ?? null
        const contentType = config.content_type ?? 'text'
        if (text) rows.push({ key: 'Text', val: typeof text === 'object' ? Object.values(text)[0] : text, mono: false })
        rows.push({ key: 'Type', val: contentType, muted: true })
        const btns = config.buttons?.length ?? 0
        if (btns > 0) rows.push({ key: 'Buttons', val: `${btns} button${btns > 1 ? 's' : ''}`, muted: true })
        if (config.keyboard_mode === 'reply') rows.push({ key: 'Mode', val: 'reply keyboard', muted: true })
        return rows
    }

    if (type === 'input') {
        const rows = []
        if (config.save_to) rows.push({ key: 'Var', val: config.save_to, mono: true })
        if (config.expected_type) rows.push({ key: 'Type', val: config.expected_type, muted: true })
        return rows
    }

    if (type === 'condition') {
        return config.expression ? [{ key: 'Expr', val: config.expression, mono: true }] : []
    }

    if (type === 'delay') {
        const val = config.seconds != null
            ? `${config.seconds}s`
            : config.duration ?? '—'
        return [{ key: 'Wait', val, muted: false }]
    }

    if (type === 'set_attribute') {
        return config.key ? [{ key: 'Key', val: config.key, mono: true }] : []
    }

    if (type === 'webhook') {
        return config.url ? [{ key: 'URL', val: config.url, muted: true }] : []
    }

    return []
})

function select() {
    selectionStore.select(props.treeNode.node.id)
}

function deleteNode() {
    builderStore.deleteNode(props.treeNode.node.id)
}
</script>

<template>
    <div
        :id="`node-card-${treeNode.node.id}`"
        class="node-card"
        :class="{
            selected: isSelected,
            'has-error': hasError && !isSelected,
        }"
        @click="select"
    >
        <div class="node-card-head">
            <div
                class="node-type-icon"
                :style="{ background: colors.bg, color: colors.color }"
            >{{ colors.icon }}</div>
            <span class="node-type-label">{{ typeLabel }}</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete node" @click.stop="deleteNode">×</button>
        </div>

        <div v-if="summaryRows.length > 0" class="node-card-body">
            <div
                v-for="row in summaryRows"
                :key="row.key"
                class="node-summary-row"
            >
                <span class="node-summary-key">{{ row.key }}</span>
                <span
                    class="node-summary-val"
                    :class="{ muted: row.muted }"
                    :style="row.mono ? { fontFamily: 'DM Mono, monospace', fontSize: '12px' } : {}"
                >{{ typeof row.val === 'string' ? row.val.slice(0, 60) : row.val }}</span>
            </div>
        </div>
    </div>
</template>
