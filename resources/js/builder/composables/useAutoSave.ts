import {watch} from 'vue';
import {useDebounceFn} from '@vueuse/core';
import {useBuilderStore} from '@builder/store/builderStore';
import type {ApiError} from '@builder/api/builderApi';
import {saveDraft, tenantStoppedNotice} from '@builder/api/builderApi';

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
    let current: Promise<void> | null = null;

    function persist(): Promise<void> {
        if (inFlight && current !== null) {
            queued = true;

            return current;
        }

        current = doPersist();

        return current;
    }

    async function doPersist(): Promise<void> {
        // A stopped tenant refuses every write, so nothing is sent from a view-only builder.
        if (store.readOnly) {
            return;
        }

        if (store.flowId == null || store.draftVersion == null) {
            return;
        }

        inFlight = true;
        store.setSaveStatus('saving');

        try {
            const { draft_version: draftVersion } = await saveDraft(
                store.flowId,
                store.definition,
                store.triggers,
                store.draftVersion,
            );

            store.setDraftVersion(draftVersion);
            store.setSaveStatus('saved');
        } catch (e) {
            const err = e as ApiError & { body?: { valid?: unknown } };
            const stopped = tenantStoppedNotice(e);
            if (stopped !== null) {
                // The mode changed while the tab was open: fall back to view-only instead of an error.
                store.enterReadOnly(stopped.notice);
            } else if (err.status === 409) {
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
            if (queued && !store.readOnly && store.saveStatus !== 'conflict') {
                queued = false;
                void persist();
            }
        }
    }

    const save = useDebounceFn(persist, 1500);

    /**
     * Sends any unsaved edit now and resolves once the draft on the server is current: `true` when
     * it is, `false` when the save failed or the builder is view-only. Callers that act on the
     * saved draft (for instance minting a webhook URL for a trigger) await this first.
     */
    async function flush(): Promise<boolean> {
        if (store.readOnly) {
            return false;
        }

        while (inFlight && current !== null) {
            await current;
        }

        if (store.isDirty || store.saveStatus === 'error') {
            await persist();

            while (inFlight && current !== null) {
                await current;
            }
        }

        return store.saveStatus === 'saved' || store.saveStatus === 'idle';
    }

    store.registerDraftFlusher(flush);

    watch(() => [store.definition, store.triggers], save, { deep: true });

    return { save, flush };
}
