<script setup lang="ts">
import {computed, ref} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import ContentEditorPanel from './ContentEditorPanel.vue'

const builderStore = useBuilderStore()

const LANGUAGE_NAMES: Record<string, string> = {
    uk: 'Ukrainian', en: 'English', ru: 'Russian', pl: 'Polish',
    de: 'German', fr: 'French', es: 'Spanish', it: 'Italian',
}

const languages = computed(() =>
    builderStore.availableLanguages.map((code) => ({
        code,
        name: LANGUAGE_NAMES[code] ?? code.toUpperCase(),
    })),
)

/**
 * Extract all translatable content entries from flow nodes.
 * Returns flat list of { key, nodeId, nodeType, nodeLabel, fieldLabel, values }.
 */
const contentEntries = computed(() => {
    const entries = []
    const nodes = builderStore.definition?.nodes ?? []

    for (const node of nodes) {
        const config = node.config ?? {}
        const type   = node.type
        const label  = node.label ?? type

        if (type === 'send_message') {
            const ct = String(config.content_type ?? 'text')

            if (ct === 'text' || ct === 'text_with_keyboard') {
                entries.push({
                    key:        `${node.id}.text`,
                    nodeId:     node.id,
                    nodeType:   type,
                    nodeLabel:  label,
                    fieldLabel: 'Message text',
                    fieldKind: 'text',
                    values:     resolveValues(config.text ?? ''),
                })
            }

            if (['image', 'document', 'video'].includes(ct)) {
                entries.push({
                    key:        `${node.id}.caption`,
                    nodeId:     node.id,
                    nodeType:   type,
                    nodeLabel:  label,
                    fieldLabel: 'Caption',
                    fieldKind: 'caption',
                    values:     resolveValues(config.caption ?? ''),
                })
            }

            const buttons = Array.isArray(config.buttons) ? config.buttons as Array<Record<string, unknown>> : []
            buttons.forEach((btn: Record<string, unknown>, idx: number) => {
                const raw = btn.label ?? ''
                const baseText = typeof raw === 'object'
                    ? ((raw as Record<string, unknown>)[builderStore.contentBaseLanguage] as string ?? Object.values(raw as Record<string, unknown>)[0] as string ?? '')
                    : String(raw)
                entries.push({
                    key:        `${node.id}.buttons.${idx}.label`,
                    nodeId:     node.id,
                    nodeType:   type,
                    nodeLabel:  label,
                    fieldLabel: baseText.trim() !== '' ? baseText : `Button ${idx + 1}`,
                    fieldKind: 'button',
                    values:     resolveValues(raw),
                })
            })
        }

        if (type === 'input') {
            if (config.prompt !== undefined && config.prompt !== null && config.prompt !== '') {
                entries.push({
                    key: `${node.id}.prompt`,
                    nodeId: node.id,
                    nodeType: type,
                    nodeLabel: label,
                    fieldLabel: 'Prompt',
                    fieldKind: 'prompt',
                    values: resolveValues(config.prompt),
                })
            }
            if (config.on_invalid_message !== undefined && config.on_invalid_message !== null && config.on_invalid_message !== '') {
                entries.push({
                    key: `${node.id}.on_invalid_message`,
                    nodeId: node.id,
                    nodeType: type,
                    nodeLabel: label,
                    fieldLabel: 'On invalid',
                    fieldKind: 'invalid',
                    values: resolveValues(config.on_invalid_message),
                })
            }
        }
    }

    return entries
})

/**
 * Group entries by nodeId for display in the left panel.
 */
const groupedEntries = computed(() => {
    const groups = new Map()
    for (const entry of contentEntries.value) {
        if (!groups.has(entry.nodeId)) {
            groups.set(entry.nodeId, { nodeId: entry.nodeId, nodeLabel: entry.nodeLabel, nodeType: entry.nodeType, fields: [] })
        }
        groups.get(entry.nodeId).fields.push(entry)
    }
    return [...groups.values()]
})

function resolveValues(raw: unknown): Record<string, string> {
    if (raw === null || raw === undefined) return {}
    if (typeof raw === 'object') return { ...(raw as Record<string, string>) }
    // plain string — treat as base language value
    return { [builderStore.contentBaseLanguage]: String(raw) }
}

/**
 * Count how many language slots have a non-empty translation. Mirrors
 * the heading inside ContentEditorPanel ("0/4 translations") — a key
 * present with an empty string is still untranslated, not "1 of 4".
 */
function filledTranslationCount(field: { values: Record<string, string> }): number {
    let count = 0
    for (const value of Object.values(field.values)) {
        if (typeof value === 'string' && value.trim() !== '') count++
    }
    return count
}

function formatNodeLabel(label: string): string {
    return label
        .replace(/_/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (char) => char.toUpperCase())
}

const selectedKey = ref<string | null>(null)
const selectedEntry = computed(() => contentEntries.value.find((e) => e.key === selectedKey.value) ?? null)

const localTranslations = ref<Record<string, string>>({})

function selectEntry(key: string) {
    if (selectedKey.value === key) return
    selectedKey.value = key
    const entry = contentEntries.value.find((e) => e.key === key)
    localTranslations.value = entry ? { ...entry.values } : {}
}

function onUpdate(key: string, langCode: string, value: string) {
    localTranslations.value[langCode] = value
}

function onSave() {
    if (!selectedKey.value) return
    const entry = contentEntries.value.find((e) => e.key === selectedKey.value)
    if (!entry) return

    const nodes = builderStore.definition.nodes
    const nodeIdx = nodes.findIndex((n) => n.id === entry.nodeId)
    if (nodeIdx === -1) return

    const node   = nodes[nodeIdx]
    const config = { ...node.config }
    const parts  = entry.key.slice(entry.nodeId.length + 1).split('.')

    if (parts[0] === 'text') {
        config.text = { ...localTranslations.value }
    } else if (parts[0] === 'caption') {
        config.caption = { ...localTranslations.value }
    } else if (parts[0] === 'prompt') {
        config.prompt = {...localTranslations.value}
    } else if (parts[0] === 'on_invalid_message') {
        config.on_invalid_message = {...localTranslations.value}
    } else if (parts[0] === 'buttons' && parts[2] === 'label') {
        const idx = Number(parts[1])
        const buttons = [...(Array.isArray(config.buttons) ? config.buttons as Array<Record<string, unknown>> : [])]
        buttons[idx] = { ...(buttons[idx] as Record<string, unknown>), label: { ...localTranslations.value } }
        config.buttons = buttons
    }

    builderStore.definition.nodes[nodeIdx] = { ...node, config }
}
</script>

<template>
    <div class="content-tab">
        <!-- Left: content keys grouped by node -->
        <div class="keys-panel">
            <div class="panel-body">
                <div v-if="groupedEntries.length === 0" class="empty">
                    No translatable fields in this flow
                </div>
                <template v-for="group in groupedEntries" :key="group.nodeId">
                    <div class="group-label">{{ formatNodeLabel(group.nodeLabel) }}</div>
                    <button
                        v-for="field in group.fields"
                        :key="field.key"
                        class="field-item"
                        :class="{ 'is-selected': selectedKey === field.key }"
                        type="button"
                        @click="selectEntry(field.key)"
                    >
                        <span
                            v-if="field.fieldKind && field.fieldKind !== 'text'"
                            :class="`field-kind--${field.fieldKind}`"
                            class="field-kind"
                        >{{
                                field.fieldKind === 'button' ? 'Btn'
                                    : field.fieldKind === 'caption' ? 'Cap'
                                        : field.fieldKind === 'prompt' ? 'Ask'
                                            : field.fieldKind === 'invalid' ? 'Err'
                                                : field.fieldKind
                            }}</span>
                        <span class="field-name">{{ field.fieldLabel }}</span>
                        <span class="lang-count">{{ filledTranslationCount(field) }}/{{ languages.length }}</span>
                    </button>
                </template>
            </div>
        </div>

        <!-- Right: translation editor -->
        <ContentEditorPanel
            :content-key="selectedEntry?.fieldLabel ?? undefined"
            :translations="localTranslations"
            :languages="languages"
            @update="onUpdate"
            @save="onSave"
        />
    </div>
</template>

<style scoped>
.content-tab { display: flex; flex: 1; overflow: hidden; }

.keys-panel {
    width: 220px;
    border-right: 1px solid var(--border);
    background: var(--bg);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
}
.panel-header {
    min-height: 42px;
    padding: 12px 14px 10px;
    border-bottom: 1px solid color-mix(in srgb, var(--primary) 18%, var(--border));
    background: linear-gradient(180deg, rgba(107, 120, 97, .86) 0%, rgba(125, 138, 112, .62) 48%, rgba(125, 138, 112, .08) 100%),
    var(--primary-bg);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, .9);
    flex-shrink: 0;
}

.panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 8px 8px 12px;
    background: var(--surface);
}
.empty { font-size: 12px; color: var(--text-3); text-align: center; padding: 24px 0; }

.group-label {
    margin: 8px 0 4px;
    padding: 6px 8px;
    border-radius: 6px;
    background: var(--surface-2);
    border: 1px solid color-mix(in srgb, var(--border) 72%, transparent);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .03em;
    color: var(--text-2);
}
.field-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: calc(100% - 12px);
    margin-left: 12px;
    padding: 5px 6px 5px 8px;
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    text-align: left;
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--text-2);
    transition: background .1s, color .1s;
}
.field-item:hover { background: var(--surface-2); color: var(--text); }
.field-item.is-selected { background: var(--primary-bg, #eef2ee); color: var(--primary); }

.field-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.lang-count {
    font-size: 10px;
    color: var(--text-3);
    flex-shrink: 0;
}

.field-kind {
    font-size: 9.5px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    padding: 1px 5px;
    border-radius: 3px;
    flex-shrink: 0;
    margin-right: 4px;
}

.field-kind--button {
    background: var(--sky-bg);
    color: var(--sky);
}

.field-kind--caption {
    background: var(--amber-bg);
    color: var(--amber);
}

.field-kind--prompt {
    background: var(--sage-bg);
    color: var(--sage);
}

.field-kind--invalid {
    background: var(--rose-bg);
    color: var(--rose);
}
</style>
