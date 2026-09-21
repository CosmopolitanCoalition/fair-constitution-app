const DRAFT_KEY = 'wos-support-signin-draft';
const MAX_DRAFT_AGE = 30 * 60 * 1000;
export const GITHUB_CATEGORIES = ['bug', 'translation', 'accessibility', 'idea'];

export function reportReference(value) {
    const clean = String(value || '').replace(/[\u0000-\u001f\u007f]/g, '').trim();
    try {
        return (/^https?:\/\//i.test(clean) ? new URL(clean).pathname : clean.split(/[?#]/)[0]).slice(0, 300);
    } catch { return ''; }
}

// No account identity, cookies, query strings or hidden diagnostic data leave the app.
export function githubReport(repository, form, origin, categoryLabel) {
    if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository || '') || !GITHUB_CATEGORIES.includes(form.category)) return null;
    const title = form.subject.trim() || `${categoryLabel}: ${form.body.trim().split('\n')[0].slice(0, 100)}`;
    const text = `${form.body.trim()}\n\n---\nCategory: ${categoryLabel}\nPage: ${reportReference(form.ref) || '(not specified)'}\nInstance: ${new URL(origin).origin}`;
    const url = new URL(`https://github.com/${repository}/issues/new`);
    url.searchParams.set('title', title.slice(0, 160));
    url.searchParams.set('body', text);
    // GitHub rejects oversized request URLs. Keep the full report available for
    // copying instead of silently truncating long or multibyte descriptions.
    const needsCopy = url.href.length > 7000;
    if (needsCopy) url.searchParams.delete('body');
    return { url: url.href, text, needsCopy };
}

export function saveReportDraft(storage, form, now = Date.now()) {
    try {
        storage.setItem(DRAFT_KEY, JSON.stringify({ at: now, category: form.category,
            subject: form.subject.slice(0, 160), body: form.body.slice(0, 5000), ref: form.ref.slice(0, 300) }));
        return true;
    } catch { return false; }
}

export function takeReportDraft(storage, ref, categories, now = Date.now()) {
    try {
        const raw = storage.getItem(DRAFT_KEY);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!Number.isFinite(data.at) || now - data.at > MAX_DRAFT_AGE || data.at > now) {
            storage.removeItem(DRAFT_KEY); return null;
        }
        if (data.ref !== ref) return null;
        storage.removeItem(DRAFT_KEY);
        if (!categories.includes(data.category) || typeof data.subject !== 'string' || typeof data.body !== 'string') return null;
        return { category: data.category, subject: data.subject.slice(0, 160), body: data.body.slice(0, 5000), ref };
    } catch { return null; }
}

export function clearReportDraft(storage) { try { storage.removeItem(DRAFT_KEY); } catch { /* Storage may be disabled. */ } }
