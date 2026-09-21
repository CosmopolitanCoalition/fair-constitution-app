import test from 'node:test';
import assert from 'node:assert/strict';
import { csrfFetch } from '../../resources/js/lib/csrf.js';

test('map request uses the current cookie after login, not the old document token', async () => {
    globalThis.document = { cookie: 'XSRF-TOKEN=current%3D', querySelector: () => ({ content: 'before-login' }) };
    globalThis.fetch = async (_url, options) => {
        assert.equal(options.headers['X-XSRF-TOKEN'], 'current=');
        assert.equal(options.headers['X-CSRF-TOKEN'], undefined);
        assert.equal(options.credentials, 'same-origin');
        return { status: 200 };
    };
    assert.equal((await csrfFetch('/civic/residency/locate', { method: 'POST' })).status, 200);
});

test('a rejected stale request retries once with the refreshed cookie', async () => {
    globalThis.document = { cookie: 'XSRF-TOKEN=old', querySelector: () => ({ content: 'old' }) };
    let calls = 0;
    globalThis.fetch = async (_url, options) => {
        calls++;
        if (calls === 1) { document.cookie = 'XSRF-TOKEN=new'; return { status: 419 }; }
        assert.equal(options.headers['X-XSRF-TOKEN'], 'new');
        return { status: 200 };
    };
    assert.equal((await csrfFetch('/civic/residency/locate')).status, 200);
    assert.equal(calls, 2);
});

test('persistent expiry gives an actionable message and does not loop', async () => {
    let calls = 0;
    globalThis.fetch = async () => { calls++; return { status: 419 }; };
    await assert.rejects(csrfFetch('/civic/residency/locate'), /Reload the page/);
    assert.equal(calls, 2);
});
