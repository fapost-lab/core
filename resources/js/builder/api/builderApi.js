const BASE = '/builder';

/**
 * @param {string} name
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

/**
 * @param {string} method
 * @param {string} url
 * @param {object|null} body
 */
async function request(method, url, body = null) {
    const options = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
        },
    };

    if (body) {
        options.body = JSON.stringify(body);
    }

    const res = await fetch(`${BASE}${url}`, options);

    if (!res.ok) {
        const error = await res.json().catch(() => ({}));
        const err = new Error(
            typeof error.message === 'string' ? error.message : 'Request failed',
        );

        err.status = res.status;
        err.body = error;

        throw err;
    }

    return res.json();
}

export const fetchNodeTypes = () =>
    request('GET', '/node-types').then((r) => r.data);

/**
 * @param {string} flowId
 * @param {{ nodes: unknown[], edges: unknown[] }} definition
 * @param {number|null} draftVersion
 */
export const saveDraft = (flowId, definition, draftVersion) =>
    request('PUT', `/flows/${flowId}/draft`, {
        definition,
        draft_version: draftVersion,
    });

/**
 * @param {string} flowId
 * @param {{ nodes: unknown[], edges: unknown[] }} definition
 */
export const validateFlow = (flowId, definition) =>
    request('POST', `/flows/${flowId}/validate`, { definition });

/**
 * @param {string} flowId
 */
export const publishFlow = (flowId) =>
    request('POST', `/flows/${flowId}/publish`);
