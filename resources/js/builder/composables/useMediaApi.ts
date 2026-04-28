import {xsrfHeaders} from '@shared/http'

const BASE = '/media'

export interface PickerFilePreview {
    kind: string
    icon_heroicon: string
    signed_url: string | null
    requires_external_render: boolean
}

export interface PickerFile {
    id: string
    name: string
    kind: string
    mime_type: string | null
    size: number | null
    preview_url: string | null
    preview: PickerFilePreview
}

export interface PickerFolder {
    id: string
    name: string
    file_count_total: number
    file_count_filtered: number
}

export interface PickerBreadcrumb {
    id: string
    name: string
}

export interface PickerContentsResponse {
    current_folder: { id: string; name: string; breadcrumbs: PickerBreadcrumb[] } | null
    subfolders: PickerFolder[]
    files: PickerFile[]
}

interface MediaApiError extends Error {
    status: number
    body: unknown
}

export async function fetchPickerContents(
    folderId: string | null,
    kind: string | null,
): Promise<PickerContentsResponse> {
    const params = new URLSearchParams()

    if (folderId) {
        params.set('folder_id', folderId)
    }

    if (kind) {
        params.set('kind', kind)
    }

    const res = await fetch(`${BASE}/picker/contents?${params}`, {
        headers: xsrfHeaders(),
    })

    if (!res.ok) {
        const body = await res.json().catch(() => ({})) as { message?: string }
        throw Object.assign(new Error(body.message ?? 'Failed to load media'), {
            status: res.status,
            body,
        }) as MediaApiError
    }

    return await res.json() as Promise<PickerContentsResponse>
}

/** Upload a file via XHR so upload progress events are available. */
export function uploadMediaFile(
    file: File,
    folderId: string | null,
    onProgress: (pct: number) => void,
): Promise<PickerFile> {
    return new Promise((resolve, reject) => {
        const form = new FormData()

        form.append('file', file)
        form.append('name', file.name)

        if (folderId) {
            form.append('folder_id', folderId)
        }

        const xhr = new XMLHttpRequest()

        xhr.open('POST', `${BASE}/files`)
        const headers = xsrfHeaders()
        Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v))

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                onProgress(Math.round((e.loaded / e.total) * 100))
            }
        })

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    resolve(JSON.parse(xhr.responseText) as PickerFile)
                } catch {
                    resolve({} as PickerFile)
                }

                return
            }

            let body: { message?: string } = {}

            try {
                body = JSON.parse(xhr.responseText) as { message?: string }
            } catch {}

            reject(
                Object.assign(new Error(body.message ?? 'Upload failed'), {
                    status: xhr.status,
                    body,
                }) as MediaApiError,
            )
        })

        xhr.addEventListener('error', () => reject(new Error('Network error during upload')))

        xhr.send(form)
    })
}
