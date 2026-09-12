/** Loading feedback belongs to foreground visits, never periodic refreshes. */
export const PROGRESS_HEADER = 'X-CGA-Page-Visit';

export function tracksVisit(visit) {
    return !!visit && !visit.prefetch && visit.showProgress !== false;
}

export function isPageNavigation({ href, target, download, prevented, modified, optOut }, current) {
    if (!href || prevented || modified || download || optOut || (target && target.toLowerCase() !== '_self')) return false;
    try {
        const from = new URL(current);
        const to = new URL(href, from);
        if (!['http:', 'https:'].includes(to.protocol) || to.origin !== from.origin) return false;
        // In-page anchors and data/file downloads leave the current page alive.
        if (to.hash && to.pathname === from.pathname && to.search === from.search) return false;
        if (/\.(pdf|csv|json|xml|zip|gz|pmtiles|png|jpe?g|webp|svg|mp[34]|webm|xlsx?|docx?)(?:$)/i.test(to.pathname)) return false;
        return !/^\/api\//.test(to.pathname);
    } catch {
        return false;
    }
}

/** Request identity prevents a completed background/older visit hiding a newer one. */
export function createNavigationProgress(notify, { schedule = setTimeout, cancel = clearTimeout } = {}) {
    const pending = new Set();
    const native = Symbol('document navigation');
    let revealTimer;
    let slowTimer;
    let requestId = 0;
    let state = { visible: false, slow: false, native: false, failed: false };
    const emit = (patch) => { state = { ...state, ...patch }; notify(state); };
    const clearTimers = () => { cancel(revealTimer); cancel(slowTimer); };

    function start(key) {
        if (pending.has(key)) return;
        const wasEmpty = pending.size === 0;
        pending.add(key);
        if (!wasEmpty) { emit({ native: pending.has(native) }); return; }
        clearTimers();
        emit({ visible: false, slow: false, native: key === native, failed: false });
        revealTimer = schedule(() => emit({ visible: true }), 100);
        slowTimer = schedule(() => emit({ slow: true }), 8000);
    }

    function finish(key) {
        if (!pending.delete(key)) return;
        if (pending.size) { emit({ native: pending.has(native) }); return; }
        clearTimers();
        if (!state.failed) emit({ visible: false, slow: false, native: false });
    }

    function reset() {
        pending.clear();
        clearTimers();
        emit({ visible: false, slow: false, native: false, failed: false });
    }

    return {
        startVisit: (visit) => {
            if (!tracksVisit(visit) || pending.has(visit)) return;
            // Inertia emits start before constructing its HTTP headers. This
            // request-local marker distinguishes concurrent reloads of one URL.
            visit.headers = { ...visit.headers, [PROGRESS_HEADER]: String(++requestId) };
            start(visit);
        },
        finishVisit: finish,
        startDocument: () => start(native),
        finishDocument: () => finish(native),
        failRequest: (request) => {
            // Inertia's global exception event also fires for silent polling.
            // Silent requests carry no marker and can never fail the page indicator.
            const marker = request?.headers?.get?.(PROGRESS_HEADER)
                ?? request?.headers?.[PROGRESS_HEADER] ?? request?.headers?.[PROGRESS_HEADER.toLowerCase()];
            if (!marker) return;
            const failed = [...pending].filter(visit => visit !== native
                && visit.headers?.[PROGRESS_HEADER] === String(marker));
            if (!failed.length) return;
            failed.forEach(visit => pending.delete(visit));
            if (pending.size) { emit({ native: pending.has(native) }); return; }
            clearTimers();
            emit({ visible: true, slow: false, native: false, failed: true });
        },
        reset,
    };
}
