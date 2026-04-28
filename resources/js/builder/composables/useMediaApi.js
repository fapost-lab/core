const BASE = '/media';

/**
 * @param {string} name
 * @returns {string}
 */
function getCookie(name) {
    const raw =
        document.cookie
            .split('; ')
            .find((row) => row.startsWith(`${name}=`))
            ?.split('=')
            .slice(1)
            .join('=') ?? '';

    if (!raw) {
        return '';
    }

    try {
        return decodeURIComponent(raw);
    } catch {
        return raw;
    }
}

function xsrfToken() {
    return getCookie('XSRF-TOKEN');
}

/**
 * @typedef {{
 *   id: string,
 *   name: string,
 *   kind: string,
 *   mime_type: string|null,
 *   size: number|null,
 *   preview_url: string|null,
 *   preview: { kind: string, icon_heroicon: string, signed_url: string|null, requires_external_render: boolean },
 * }} PickerFile
 *
 * @typedef {{
 *   id: string,
 *   name: string,
 *   file_count_total: number,
 *   file_count_filtered: number,
 * }} PickerFolder
 *
 * @typedef {{
 *   current_folder: { id: string, name: string, breadcrumbs: Array<{id:string,name:string}> }|null,
 *   subfolders: PickerFolder[],
 *   files: PickerFile[],
 * }} PickerContentsResponse
 */

/**
 * @param {string|null} folderId
 * @param {string|null} kind
 * @returns {Promise<PickerContentsResponse>}
 */
export async function fetchPickerContents(folderId, kind) {
    const params = new URLSearchParams();

    if (folderId) {
        params.set('folder_id', folderId);
    }

    if (kind) {
        params.set('kind', kind);
    }

    const res = await fetch(`${BASE}/picker/contents?${params}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        const err = new Error(body.message ?? 'Failed to load media');

        err.status = res.status;
        err.body = body;

        throw err;
    }

    return res.json();
}

/**
 * Upload a file via XHR so upload progress events are available.
 *
 * @param {File} file
 * @param {string|null} folderId
 * @param {(pct: number) => void} onProgress
 * @returns {Promise<object>}
 */
export function uploadMediaFile(file, folderId, onProgress) {
    return new Promise((resolve, reject) => {
        const form = new FormData();

        form.append('file', file);
        form.append('name', file.name);

        if (folderId) {
            form.append('folder_id', folderId);
        }

        const xhr = new XMLHttpRequest();

        xhr.open('POST', `${BASE}/files`);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                onProgress(Math.round((e.loaded / e.total) * 100));
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    resolve(JSON.parse(xhr.responseText));
                } catch {
                    resolve({});
                }

                return;
            }

            let body = {};

            try {
                body = JSON.parse(xhr.responseText);
            } catch {}

            const err = new Error(body.message ?? 'Upload failed');

            err.status = xhr.status;
            err.body = body;

            reject(err);
        });

        xhr.addEventListener('error', () => reject(new Error('Network error during upload')));

        xhr.send(form);
    });
}
