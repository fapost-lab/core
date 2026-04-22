<script setup>
import { onMounted } from 'vue'
import { useBuilderStore }  from '@builder/store/builderStore'
import { useRegistryStore } from '@builder/store/registryStore'
import { useAutoSave }      from '@builder/composables/useAutoSave'
import AppTopBar            from '@builder/components/AppTopBar.vue'
import FlowStructure        from '@builder/components/editor/FlowStructure.vue'
import FlowSequence         from '@builder/components/editor/FlowSequence.vue'
import ConfigPanel          from '@builder/components/editor/ConfigPanel.vue'
import ContentTab           from '@builder/components/content/ContentTab.vue'

const props = defineProps({
    flow:    { type: Object, required: true },
    backUrl: { type: String, default: null },
})

const builderStore  = useBuilderStore()
const registryStore = useRegistryStore()
const { save }      = useAutoSave()

builderStore.init(props.flow)

onMounted(async () => {
    await registryStore.load()
})
</script>

<template>
    <div class="h-screen flex flex-col">
        <AppTopBar
            :flow-name="builderStore.flowName"
            :draft-version="builderStore.draftVersion"
            :published-version="flow.publishedVersion ?? null"
            :save-status="builderStore.saveStatus"
            :active-tab="builderStore.activeTab"
            :back-url="backUrl"
            @tab-change="builderStore.setActiveTab"
            @save-draft="save"
            @publish="() => {}"
            @validate="() => {}"
            @rollback="() => {}"
            @preview="() => {}"
        />

        <div
            v-if="builderStore.activeTab === 'builder'"
            class="flex-1 flex overflow-hidden"
        >
            <FlowStructure class="w-56 border-r shrink-0 overflow-y-auto" />
            <FlowSequence class="flex-1 overflow-y-auto px-8 py-6" />
            <ConfigPanel class="w-72 border-l shrink-0 overflow-y-auto" />
        </div>

        <ContentTab v-else />
    </div>
</template>
