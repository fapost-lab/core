import { useBuilderStore } from '@builder/store/builderStore';
import { useValidation } from '@builder/composables/useValidation';
import { publishFlow } from '@builder/api/builderApi';

export function usePublish() {
    const store = useBuilderStore();
    const { validate } = useValidation();

    async function publish() {
        const result = await validate({ open: false });
        if (!result.valid) {
            store.setValidationResult(result, true);
            return { success: false };
        }

        store.setSaveStatus('saving');

        try {
            const data = await publishFlow(store.flowId);
            store.setPublishedVersion(data.version);
            store.setSaveStatus('saved');

            return { success: true, version: data.version };
        } catch (error) {
            if (error?.status === 422 && error?.body) {
                const payload = error.body;
                if (typeof payload.valid === 'boolean') {
                    store.setValidationResult(payload);
                } else if (Array.isArray(payload.errors)) {
                    store.setValidationResult({ valid: false, errors: payload.errors });
                }

                return { success: false };
            }

            store.setSaveStatus('error');
            throw error;
        }
    }

    return { publish };
}
