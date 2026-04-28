import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchPickerContents } from '@builder/composables/useMediaApi';

/**
 * @typedef {{
 *   id: string,
 *   name: string,
 *   progress: number,
 *   status: 'uploading'|'done'|'error',
 *   error: string|null,
 * }} UploadEntry
 */

/**
 * Shared navigation state for the media picker modal.
 * Reset on every open via reset(); navigation state persists across folder clicks.
 */
export const useMediaPickerStore = defineStore('mediaPicker', () => {
    const loading = ref(false);
    const error = ref(/** @type {string|null} */ (null));

    const currentFolderId = ref(/** @type {string|null} */ (null));
    const currentFolder = ref(/** @type {object|null} */ (null));
    const breadcrumbs = ref(/** @type {Array<{id: string, name: string}>} */ ([]));
    const subfolders = ref(/** @type {object[]} */ ([]));
    const files = ref(/** @type {object[]} */ ([]));
    const kindFilter = ref(/** @type {string|null} */ (null));
    const uploads = ref(/** @type {UploadEntry[]} */ ([]));

    /**
     * Navigate to a folder (null = root).
     * @param {string|null} folderId
     */
    async function navigate(folderId) {
        loading.value = true;
        error.value = null;

        try {
            const data = await fetchPickerContents(folderId ?? null, kindFilter.value);

            currentFolderId.value = folderId ?? null;
            currentFolder.value = data.current_folder ?? null;
            breadcrumbs.value = data.current_folder?.breadcrumbs ?? [];
            subfolders.value = data.subfolders ?? [];
            files.value = data.files ?? [];
        } catch (e) {
            error.value = /** @type {Error} */ (e).message ?? 'Failed to load';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Reset state and apply kind filter before opening the modal.
     * @param {string|null} kind
     */
    function reset(kind) {
        kindFilter.value = kind ?? null;
        currentFolderId.value = null;
        currentFolder.value = null;
        breadcrumbs.value = [];
        subfolders.value = [];
        files.value = [];
        error.value = null;
        uploads.value = [];
    }

    /**
     * Prepend a freshly-uploaded file to the current view.
     * @param {object} file
     */
    function prependFile(file) {
        files.value = [file, ...files.value];
    }

    /**
     * @param {string} id
     * @param {string} name
     * @returns {string} entry id
     */
    function addUpload(id, name) {
        uploads.value.push({ id, name, progress: 0, status: 'uploading', error: null });

        return id;
    }

    /**
     * @param {string} id
     * @param {number} progress  0–100
     */
    function setUploadProgress(id, progress) {
        const entry = uploads.value.find((u) => u.id === id);

        if (entry) {
            entry.progress = progress;
        }
    }

    /**
     * @param {string} id
     */
    function markUploadDone(id) {
        const entry = uploads.value.find((u) => u.id === id);

        if (entry) {
            entry.status = 'done';
            entry.progress = 100;
        }
    }

    /**
     * @param {string} id
     * @param {string} message
     */
    function markUploadError(id, message) {
        const entry = uploads.value.find((u) => u.id === id);

        if (entry) {
            entry.status = 'error';
            entry.error = message;
        }
    }

    /**
     * @param {string} id
     */
    function removeUpload(id) {
        uploads.value = uploads.value.filter((u) => u.id !== id);
    }

    return {
        loading, error,
        currentFolderId, currentFolder, breadcrumbs,
        subfolders, files, kindFilter, uploads,
        navigate, reset, prependFile,
        addUpload, setUploadProgress, markUploadDone, markUploadError, removeUpload,
    };
});
