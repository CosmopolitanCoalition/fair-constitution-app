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
