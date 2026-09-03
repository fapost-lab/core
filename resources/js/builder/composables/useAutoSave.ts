import {watch} from 'vue';
import {useDebounceFn} from '@vueuse/core';
import {useBuilderStore} from '@builder/store/builderStore';
import type {ApiError} from '@builder/api/builderApi';
import {saveDraft} from '@builder/api/builderApi';

export function useAutoSave() {
    const store = useBuilderStore();

    /**
     * Draft saves are optimistic-locked on `draft_version`, so they must never
     * overlap: a second request built from the version the first one is about
     * to bump comes back 409 and the editor is stuck in `conflict` with every
     * later edit unsaved. Debouncing alone does not prevent that — it only
     * delays the call, not the overlap with a request still in flight.
     */
    let inFlight = false;
    let queued   = false;

    async function persist(): Promise<void> {
        if (store.flowId == null || store.draftVersion == null) {
            return;
        }

        if (inFlight) {
            queued = true;

            return;
        }

        inFlight = true;
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
        } catch (e) {
            const err = e as ApiError & { body?: { valid?: unknown } };
            if (err.status === 409) {
                store.setSaveStatus('conflict');
            } else if (err.status === 422 && typeof err.body?.valid === 'boolean') {
                store.setValidationResult(err.body as { valid: boolean }, true);
                store.setSaveStatus('error');
            } else {
                store.setSaveStatus('error');
            }
        } finally {
            inFlight = false;

            // Edits that landed mid-request go out now, against the version the
            // server just handed back.
            if (queued && store.saveStatus !== 'conflict') {
                queued = false;
                void persist();
            }
        }
    }

    const save = useDebounceFn(persist, 1500);

    watch(() => [store.definition, store.trigger], save, { deep: true });

    return { save };
}
