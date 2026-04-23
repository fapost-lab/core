import { watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useBuilderStore } from '@builder/store/builderStore';
import { saveDraft } from '@builder/api/builderApi';

export function useAutoSave() {
    const store = useBuilderStore();

    const save = useDebounceFn(async () => {
        if (store.flowId == null || store.draftVersion == null) {
            return;
        }

        store.setSaveStatus('saving');

        try {
            const { draft_version: draftVersion } = await saveDraft(
                store.flowId,
                store.definition,
                store.trigger,
                store.draftVersion,
            );

            store.setDraftVersion(draftVersion);
            store.setSaveStatus('saved');
        } catch (err) {
            if (err.status === 409) {
                store.setSaveStatus('conflict');
            } else {
                store.setSaveStatus('error');
            }
        }
    }, 1500);

    watch(() => [store.definition, store.trigger], save, { deep: true });

    return { save };
}
