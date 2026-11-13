<script setup lang="ts">
import {computed, type CSSProperties, nextTick, onMounted, ref, useTemplateRef, watch} from 'vue'
import {onClickOutside, useEventListener} from '@vueuse/core'
import {useRegistryStore} from '@builder/store/registryStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {paletteNodeTypes} from '@builder/utils/templateNodes'
import {groupPaletteTypes} from '@builder/utils/paletteGroups'
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
const userToggled    = ref(false)
const paletteRef     = useTemplateRef('paletteRef')
const openCategories = ref(new Set<string>())
const paletteStyle   = ref<CSSProperties>({
    position: 'fixed',
    top: '0px',
    left: '0px',
    transform: 'translateX(-50%)',
})

// Auto-managed types (palette === false) are registered for rendering/config
// lookup but never inserted by hand — exclude them from the palette.
// Template nodes are kind-specific: `template` only in a flow, `template_output` only in a template.
const insertableNodeTypes = computed(() => paletteNodeTypes(registryStore.nodeTypes, builderStore.kind))

const filteredNodeTypes = computed(() => {
    const query = searchQuery.value.trim().toLowerCase()
    if (!query) return insertableNodeTypes.value
    return insertableNodeTypes.value.filter((n) => (n.label ?? '').toLowerCase().includes(query))
})

const groups = computed(() => groupPaletteTypes(filteredNodeTypes.value, registryStore.groups))

const categoryNames = computed(() => groups.value.map((g) => g.key))

const groupsByKey = computed(() => new Map(groups.value.map((g) => [g.key, g])))

// Nothing opened by hand yet → the first group is open.
const effectiveOpen = computed(() =>
    userToggled.value ? openCategories.value : new Set(categoryNames.value.slice(0, 1)),
)

const visibleCategoryNames = computed(() => {
    if (searchQuery.value.trim()) {
        return categoryNames.value
    }

    return categoryNames.value.filter((categoryName) => effectiveOpen.value.has(categoryName))
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

    return effectiveOpen.value.has(categoryName)
}

function toggleCategory(categoryName: string) {
    if (searchQuery.value.trim()) {
        return
    }

    const next = new Set(effectiveOpen.value)
    userToggled.value = true

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
                        <span>{{ groupsByKey.get(category)?.label ?? category }}</span>
                        <span>{{ isCategoryOpen(category) ? '▾' : '▸' }}</span>
                    </button>

                    <template v-if="visibleCategoryNames.includes(category)">
                        <button
                            v-for="nodeType in groupsByKey.get(category)?.types"
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
