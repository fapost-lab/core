<script setup lang="ts">
import {computed, type CSSProperties, nextTick, onMounted, ref, useTemplateRef, watch} from 'vue'
import {onClickOutside, useEventListener} from '@vueuse/core'
import {useRegistryStore} from '@builder/store/registryStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {nodeColors} from '@builder/utils/nodeColors'
import NodeIcon from '@builder/components/NodeIcon.vue'

const props = defineProps({
    afterNodeId: { type: String as () => string | null, default: null },
    handle:      { type: String, default: 'default' },
    palettePos:  { type: Object, default: () => ({ top: 0, left: 0 }) },
})

const emit = defineEmits(['select', 'close'])

const registryStore  = useRegistryStore()
const builderStore   = useBuilderStore()
const selectionStore = useSelectionStore()
const searchQuery    = ref('')
const paletteRef     = useTemplateRef('paletteRef')
const openCategories = ref(new Set(['Core']))
const paletteStyle   = ref<CSSProperties>({
    position: 'fixed',
    top: '0px',
    left: '0px',
    transform: 'translateX(-50%)',
})

const filteredNodeTypes = computed(() => {
    const query = searchQuery.value.trim().toLowerCase()
    if (!query) return registryStore.nodeTypes
    return registryStore.nodeTypes.filter((n) => (n.label ?? '').toLowerCase().includes(query))
})

const grouped = computed((): Record<string, typeof filteredNodeTypes.value> =>
    filteredNodeTypes.value.reduce((acc: Record<string, typeof filteredNodeTypes.value>, n) => {
        const cat = n.category ?? 'Core'
        if (!acc[cat]) acc[cat] = []
        acc[cat].push(n)
        return acc
    }, {}),
)

const categoryNames = computed(() => Object.keys(grouped.value))

const visibleCategoryNames = computed(() => {
    if (searchQuery.value.trim()) {
        return categoryNames.value
    }

    return categoryNames.value.filter((categoryName) => openCategories.value.has(categoryName))
})

function insert(type: string, version: number) {
    const newId = builderStore.insertNode(props.afterNodeId ?? '', props.handle, type, version)
    if (newId != null) {
        selectionStore.select(newId)
    }
    emit('select')  // always close palette regardless of whether insertion succeeded
}

function isCategoryOpen(categoryName: string) {
    if (searchQuery.value.trim()) {
        return true
    }

    return openCategories.value.has(categoryName)
}

function toggleCategory(categoryName: string) {
    if (searchQuery.value.trim()) {
        return
    }

    const next = new Set(openCategories.value)

    if (next.has(categoryName)) {
        next.delete(categoryName)
    } else {
        next.add(categoryName)
    }

    openCategories.value = next
}

function repositionPalette() {
    const paletteEl = paletteRef.value
    if (!paletteEl) {
        return
    }

    const margin = 8
    const rect = paletteEl.getBoundingClientRect()
    const viewportHeight = window.innerHeight
    const viewportWidth = window.innerWidth

    let top = props.palettePos.top ?? 0
    const anchorTop = props.palettePos.anchorTop ?? top
    const topIfOpenAbove = anchorTop - rect.height - 6

    if (top + rect.height + margin > viewportHeight && topIfOpenAbove >= margin) {
        top = topIfOpenAbove
    }

    top = Math.min(Math.max(margin, top), Math.max(margin, viewportHeight - rect.height - margin))

    let left = props.palettePos.left ?? rect.width / 2
    const halfWidth = rect.width / 2
    left = Math.min(Math.max(margin + halfWidth, left), Math.max(margin + halfWidth, viewportWidth - halfWidth - margin))

    paletteStyle.value = {
        position: 'fixed' as const,
        top: `${top}px`,
        left: `${left}px`,
        transform: 'translateX(-50%)',
    }
}

async function syncPalettePosition() {
    await nextTick()
    repositionPalette()
}

onMounted(syncPalettePosition)

watch(() => props.palettePos, syncPalettePosition, { deep: true })
watch(searchQuery, syncPalettePosition)

useEventListener(window, 'resize', repositionPalette)

onClickOutside(paletteRef, () => emit('close'))
useEventListener(document, 'keydown', (e) => { if (e.key === 'Escape') emit('close') })
</script>

<template>
    <div
        ref="paletteRef"
        class="node-palette"
        :style="paletteStyle"
    >
        <div class="node-palette-search">
            <input
                v-model="searchQuery"
                placeholder="Search blocks…"
                autofocus
            >
        </div>

        <div class="node-palette-list">
            <template v-if="categoryNames.length > 0">
                <template v-for="category in categoryNames" :key="category">
                    <button
                        class="node-palette-category"
                        type="button"
                        @click="toggleCategory(category)"
                    >
                        <span>{{ category }}</span>
                        <span>{{ isCategoryOpen(category) ? '▾' : '▸' }}</span>
                    </button>

                    <template v-if="visibleCategoryNames.includes(category)">
                        <button
                            v-for="nodeType in grouped[category]"
                            :key="`${nodeType.type}@${nodeType.version}`"
                            class="node-palette-item"
                            @click="insert(nodeType.type, nodeType.version)"
                        >
                            <div
                                class="palette-icon"
                                :style="{ background: nodeColors(nodeType.type).bg, color: nodeColors(nodeType.type).color }"
                            >
                                <NodeIcon :type="nodeType.type" />
                            </div>
                            {{ nodeType.label }}
                        </button>
                    </template>
                </template>
            </template>
            <div v-else class="node-palette-empty">No blocks found</div>
        </div>
    </div>
</template>
