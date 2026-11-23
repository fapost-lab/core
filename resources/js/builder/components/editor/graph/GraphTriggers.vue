<script setup lang="ts">
import {computed} from 'vue'
import type {BuilderTrigger} from '@builder/dto/types'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {usePermissions} from '@builder/composables/usePermissions'
import {useTranslations} from '@builder/composables/useTranslations'

/**
 * The flow's triggers above the graph, as the tree shows them above its sequence: a click opens one in the shared
 * ConfigPanel; adding and removing go through the store, and an automatic trigger is removed only with the Manage
 * automations permission (the server stays the arbiter). A template has no trigger, so none is drawn for it.
 */
const {t} = useTranslations()
const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()
const {canRemoveTrigger} = usePermissions()

const cards = computed(() =>
    builderStore.triggers.map((trigger: BuilderTrigger) => {
        const event = trigger.type === 'event' ? (trigger.config?.event_name as string | undefined) : undefined

        return {
            id: trigger.id,
            label: t(`graph.trigger_types.${trigger.type}`),
            sub: event !== undefined && event !== '' ? event : null,
            removable: canRemoveTrigger(trigger.type),
        }
    }),
)

function add(): void {
    const id = builderStore.addTrigger()
    if (id !== '') {
        selectionStore.select(`__trigger__:${id}`)
    }
}

function remove(id: string): void {
    if (selectionStore.selectedTriggerId === id) {
        selectionStore.clear()
    }
    builderStore.deleteTrigger(id)
}
</script>

<template>
    <div class="graph-triggers" @click.stop>
        <span class="graph-triggers-title">⚡ {{ t('graph.triggers') }}</span>
        <span v-if="cards.length === 0" class="graph-trigger graph-trigger--empty">{{ t('graph.no_trigger') }}</span>
        <div
            v-for="card in cards"
            :key="card.id"
            class="graph-trigger"
            :class="{ 'graph-trigger--selected': selectionStore.selectedTriggerId === card.id }"
            role="button"
            tabindex="0"
            @click="selectionStore.selectTrigger(card.id)"
            @keydown.enter="selectionStore.selectTrigger(card.id)"
        >
            <span class="graph-trigger-label">{{ card.label }}</span>
            <span v-if="card.sub" class="graph-trigger-sub">{{ card.sub }}</span>
            <button
                v-if="!builderStore.readOnly && card.removable"
                type="button"
                class="graph-trigger-remove"
                :title="t('graph.remove_trigger')"
                :aria-label="t('graph.remove_trigger')"
                @click.stop="remove(card.id)"
            >×</button>
        </div>
        <button v-if="!builderStore.readOnly" type="button" class="graph-trigger-add" @click="add">
            + {{ t('graph.add_trigger') }}
        </button>
    </div>
</template>

<style scoped>
.graph-triggers {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    z-index: 5;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    pointer-events: none;
}
.graph-triggers > * { pointer-events: all; }
.graph-triggers-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-2);
    margin-right: 2px;
}
.graph-trigger {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 28px;
    padding: 0 6px 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    box-shadow: var(--shadow);
    font-size: 12.5px;
    color: var(--text);
    cursor: pointer;
}
.graph-trigger--empty {
    padding-right: 10px;
    border-style: dashed;
    color: var(--text-3);
    cursor: default;
}
.graph-trigger--selected {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 10%, transparent);
}
.graph-trigger-sub {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-3);
}
.graph-trigger-remove {
    width: 18px;
    height: 18px;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: var(--text-3);
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
}
.graph-trigger-remove:hover { color: var(--rose); background: var(--surface-2); }
.graph-trigger-add {
    height: 28px;
    padding: 0 10px;
    border: 1px dashed var(--border-2);
    border-radius: var(--radius);
    background: var(--surface);
    font-family: var(--font-sans);
    font-size: 12.5px;
    color: var(--text-2);
    cursor: pointer;
}
.graph-trigger-add:hover { color: var(--primary); border-color: var(--primary); }
</style>
