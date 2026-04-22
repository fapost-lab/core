<script setup>
import { onMounted } from 'vue'
import { useBuilderStore }  from '@builder/store/builderStore'
import { useRegistryStore } from '@builder/store/registryStore'
import { useAutoSave }      from '@builder/composables/useAutoSave'
import AppTopBar            from '@builder/components/AppTopBar.vue'
import StructurePanel       from '@builder/components/builder/StructurePanel.vue'
import SequencePanel        from '@builder/components/builder/SequencePanel.vue'
import ConfigPanel          from '@builder/components/config/ConfigPanel.vue'
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
    <div style="display:flex; flex-direction:column; height:100vh; overflow:hidden;">
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
            style="display:flex; flex:1; overflow:hidden;"
        >
            <StructurePanel />
            <SequencePanel />
            <ConfigPanel />
        </div>

        <ContentTab v-else />
    </div>
</template>
