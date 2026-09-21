import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';

function worker() {
    const handlers = {}, notifications = [], opened = [];
    const self = { location: { origin: 'https://world.test' },
        addEventListener: (event, fn) => handlers[event] = fn,
        registration: { showNotification: async (...args) => notifications.push(args) },
        clients: { openWindow: async path => opened.push(path) },
    };
    vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), { self, URL });
    return { handlers, notifications, opened };
}
test('push shows privacy-safe fallback and notification click cannot leave this origin', async () => {
    const { handlers, notifications, opened } = worker(); let pending;
    handlers.push({ data: { json: () => ({ title: 'Civic reminder', body: 'Deadline soon', url: 'https://evil.test/', tag: 'one' }) }, waitUntil: p => pending = p });
    await pending;
    assert.equal(notifications[0][0], 'Civic reminder');
    assert.equal(notifications[0][1].data.url, 'https://world.test/system/app');
    handlers.notificationclick({ notification: { close() {}, data: { url: 'javascript:alert(1)' } }, waitUntil: p => pending = p });
    await pending; assert.deepEqual(opened, ['https://world.test/system/app']);
});
test('worker leaves mutations and API reads entirely to the network', () => {
    const { handlers } = worker();
    for (const request of [{ method: 'POST', mode: 'navigate' }, { method: 'GET', mode: 'cors' }]) {
        handlers.fetch({ request, respondWith: () => assert.fail('Private/API requests must not be intercepted') });
    }
});
