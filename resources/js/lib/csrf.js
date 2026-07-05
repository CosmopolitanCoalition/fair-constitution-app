// Robust CSRF header for raw fetch() calls in the setup wizard.
//
// Laravel refreshes the XSRF-TOKEN cookie on EVERY response, so it stays valid across the session
// regeneration that happens when the operator account is created mid-wizard. The
// <meta name="csrf-token"> tag, by contrast, holds the token from the ORIGINAL page load until a full
// reload — so after account creation an Inertia-navigated page (the SOLO/JOIN fork, the JOIN screen)
// still carries the pre-login token and the first POST 419s (the "refresh and it works" bug).
//
// Prefer the cookie (sent as X-XSRF-TOKEN, which Laravel decrypts + validates); fall back to the meta
// token (sent raw as X-CSRF-TOKEN) when no cookie is present.
export function csrfHeaders() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    if (m) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) }
    }
    return { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }
}

// Drop-in fetch() for wizard mutations. Injects fresh CSRF headers per attempt
// and absorbs the one legitimate 419: a request racing the session rotation.
// The 419 response itself carries a NEW XSRF cookie, so a single retry with
// re-read headers succeeds; a second 419 means the session is truly gone, and
// callers get an honest, human error instead of "token mismatch".
export async function csrfFetch(url, options = {}) {
    const attempt = () => fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'Accept': 'application/json',
            ...(options.headers || {}),
            ...csrfHeaders(),
        },
    })

    let res = await attempt()
    if (res.status === 419) {
        res = await attempt()
        if (res.status === 419) {
            throw new Error('Your session refreshed mid-step. Reload the page and try again — nothing was lost.')
        }
    }
    return res
}
