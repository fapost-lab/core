import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { PickerBreadcrumb, PickerFile, PickerFolder } from '@builder/composables/useMediaApi'
import { fetchPickerContents } from '@builder/composables/useMediaApi'

interface UploadEntry {
    id: string
    name: string
    progress: number
    status: 'uploading' | 'done' | 'error'
    error: string | null
}

interface CurrentFolder {
    id: string
    name: string
    breadcrumbs: PickerBreadcrumb[]
}

/** Shared navigation state for the media picker modal. Reset on every open via reset(). */
export const useMediaPickerStore = defineStore('mediaPicker', () => {
    const loading = ref(false)
    const error = ref<string | null>(null)
    const currentFolderId = ref<string | null>(null)
    const currentFolder = ref<CurrentFolder | null>(null)
    const breadcrumbs = ref<PickerBreadcrumb[]>([])
    const subfolders = ref<PickerFolder[]>([])
    const files = ref<PickerFile[]>([])
    const kindFilter = ref<string | null>(null)
    const uploads = ref<UploadEntry[]>([])

    /** Navigate to a folder (null = root). */
    async function navigate(folderId: string | null) {
        loading.value = true
        error.value = null

        try {
            const data = await fetchPickerContents(folderId ?? null, kindFilter.value)

            currentFolderId.value = folderId ?? null
            currentFolder.value = data.current_folder ?? null
            breadcrumbs.value = data.current_folder?.breadcrumbs ?? []
            subfolders.value = data.subfolders ?? []
            files.value = data.files ?? []
        } catch (e) {
            error.value = (e as Error).message ?? 'Failed to load'
        } finally {
            loading.value = false
        }
    }

    /** Reset state and apply kind filter before opening the modal. */
    function reset(kind: string | null) {
        kindFilter.value = kind ?? null
        currentFolderId.value = null
        currentFolder.value = null
        breadcrumbs.value = []
        subfolders.value = []
        files.value = []
        error.value = null
        uploads.value = []
    }

    /** Prepend a freshly-uploaded file to the current view. */
    function prependFile(file: PickerFile) {
        files.value = [file, ...files.value]
    }

    function addUpload(id: string, name: string): string {
        uploads.value.push({ id, name, progress: 0, status: 'uploading', error: null })
        return id
    }

    function setUploadProgress(id: string, progress: number) {
        const entry = uploads.value.find((u) => u.id === id)
        if (entry) {
            entry.progress = progress
        }
    }

    function markUploadDone(id: string) {
        const entry = uploads.value.find((u) => u.id === id)
        if (entry) {
            entry.status = 'done'
            entry.progress = 100
        }
    }

    function markUploadError(id: string, message: string) {
        const entry = uploads.value.find((u) => u.id === id)
        if (entry) {
            entry.status = 'error'
            entry.error = message
        }
    }

    function removeUpload(id: string) {
        uploads.value = uploads.value.filter((u) => u.id !== id)
    }

    return {
        loading, error,
        currentFolderId, currentFolder, breadcrumbs,
        subfolders, files, kindFilter, uploads,
        navigate, reset, prependFile,
        addUpload, setUploadProgress, markUploadDone, markUploadError, removeUpload,
    }
})
