<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import TagInput from '../fields/TagInput.vue'
import {useKnownTags} from '@builder/composables/useKnownTags'

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const emit = defineEmits(['update:config'])

const knownTags = useKnownTags()

// Action options come from the (already localized) backend config schema, so
// the labels match the admin UI language without a second source of truth.
const actionOptions = computed<Array<{ value: string; label: string }>>(() => {
    const raw = (props.schema.action as { options?: unknown } | undefined)?.options
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
        return Object.entries(raw as Record<string, string>).map(([value, label]) => ({value, label}))
    }
    return [
        {value: 'add', label: 'Add'},
        {value: 'remove', label: 'Remove'},
        {value: 'toggle', label: 'Toggle'},
    ]
})

function loadAction(): string {
    const a = (props.node.config as { action?: unknown } | undefined)?.action
    return typeof a === 'string' && a !== '' ? a : 'add'
}

function loadTags(): string[] {
    const t = (props.node.config as { tags?: unknown } | undefined)?.tags
    const list = Array.isArray(t) ? t.filter((x): x is string => typeof x === 'string') : []
    return list.length > 0 ? list : ['']
}

const action = ref<string>(loadAction())
const tags   = ref<string[]>(loadTags())

watch(
    () => props.node.id,
    () => {
        action.value = loadAction()
        tags.value   = loadTags()
    },
)

function persist() {
    emit('update:config', {action: action.value, tags: tags.value})
}

function onActionChange(event: Event) {
    action.value = (event.target as HTMLSelectElement).value
    persist()
}

function onTagUpdate(index: number, next: string) {
    tags.value[index] = next
    persist()
}

function addTag() {
    tags.value.push('')
    persist()
}

function removeTag(index: number) {
    if (tags.value.length <= 1) {
        tags.value[0] = ''
    } else {
        tags.value.splice(index, 1)
    }
    persist()
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Tagging" default-open>
            <div class="config-field">
                <div class="field-label">{{ (schema.action as { label?: string })?.label ?? 'Action' }}</div>
                <select class="field-input" :value="action" @change="onActionChange">
                    <option v-for="opt in actionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>

            <div class="config-field">
                <div class="field-label">{{ (schema.tags as { label?: string })?.label ?? 'Tags' }}</div>
                <div class="tag-rows">
                    <div v-for="(tag, index) in tags" :key="index" class="tag-row">
                        <TagInput
                            :model-value="tag"
                            :known-tags="knownTags"
                            @update:model-value="(next: string) => onTagUpdate(index, next)"
                        />
                        <button
                            type="button"
                            class="tag-remove"
                            title="Remove tag"
                            @click="removeTag(index)"
                        >×</button>
                    </div>

                    <button type="button" class="tag-add" @click="addTag">+ Add tag</button>
                </div>
                <p v-if="(schema.tags as { help?: string })?.help" class="field-help">
                    {{ (schema.tags as { help?: string }).help }}
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'Victor Mono',monospace;font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.tag-rows {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.tag-row {
    display: flex;
    align-items: center;
    gap: 6px;
}
.tag-remove {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface-2);
    color: var(--text-2);
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
}
.tag-remove:hover {
    background: var(--rose-bg, var(--surface-2));
    color: var(--rose);
    border-color: var(--rose);
}
.tag-add {
    align-self: flex-start;
    padding: 6px 12px;
    border: 1px dashed var(--border);
    border-radius: var(--radius);
    background: transparent;
    color: var(--text-2);
    font-size: 12px;
    cursor: pointer;
}
.tag-add:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.field-help {
    margin: 6px 0 0;
    font-size: 11px;
    color: var(--text-3);
    line-height: 1.35;
}
</style>
