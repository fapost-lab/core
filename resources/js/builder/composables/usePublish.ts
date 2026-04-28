import { useBuilderStore } from '@builder/store/builderStore';
import { useValidation } from '@builder/composables/useValidation';
import type { ApiError } from '@builder/api/builderApi';
import { publishFlow, saveDraft } from '@builder/api/builderApi';
import type { ValidationError } from '@builder/dto/types';

type PublishResult = { success: true; version: number } | { success: false }

export function usePublish() {
    const store = useBuilderStore();
    const { validate } = useValidation();

    async function publish(): Promise<PublishResult> {
        const result = await validate({ open: false });
        if (!result.valid) {
            store.setValidationResult(result, true);
            return { success: false };
        }

        store.setSaveStatus('saving');

        try {
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
            const { draft_version: draftVersion } = await saveDraft(
                store.flowId!,
                store.definition,
                store.trigger,
                store.draftVersion,
            );

            store.setDraftVersion(draftVersion);

            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
            const data = await publishFlow(store.flowId!);
            store.setPublishedVersion(data.version);
            store.setSaveStatus('saved');

            return { success: true, version: data.version };
        } catch (e) {
            const error = e as ApiError & { body?: { valid?: unknown; errors?: ValidationError[] } };
            if (error.status === 422 && error.body) {
                const payload = error.body;
                if (typeof payload.valid === 'boolean') {
                    store.setValidationResult(payload as { valid: boolean });
                } else if (Array.isArray(payload.errors)) {
                    store.setValidationResult({ valid: false, errors: payload.errors });
                }

                return { success: false };
            }

            store.setSaveStatus('error');
            throw e;
        }
    }

    return { publish };
}
