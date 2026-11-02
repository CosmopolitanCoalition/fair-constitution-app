import { csrfFetch } from '@/lib/csrf';

/** One call to the signed-in user's workspace API. Throws an Error carrying the server's message. */
export async function wapi(path, method = 'GET', data = null) {
    const options = { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } };
    if (data !== null) options.body = JSON.stringify(data);
    const response = method === 'GET'
        ? await fetch(`/workspace/api${path}`, { ...options, credentials: 'same-origin' })
        : await csrfFetch(`/workspace/api${path}`, options);
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.message || `Request failed (${response.status})`);
    }
    return response.status === 204 ? null : response.json();
}

/** Monday of the week that contains `date`, as a Date at local midnight. */
export function weekStart(date) {
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    return d;
}

export const isoDate = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Parse a timestamp from the API ("2026-11-04 09:00:00+00" from Postgres, or without a zone) as an instant. */
export function parseTs(value) {
    let s = String(value).replace(' ', 'T');
    if (/[+-]\d\d$/.test(s)) s += ':00';
    if (!/(Z|[+-]\d\d:\d\d)$/.test(s)) s += 'Z';
    return new Date(s);
}
