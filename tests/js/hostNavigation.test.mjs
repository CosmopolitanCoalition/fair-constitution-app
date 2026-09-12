import assert from 'node:assert/strict';
import { HOST_PAGES, HOST_SECTIONS, hostSubpages } from '../../resources/js/Components/Operator/hostNavigation.js';

assert.deepEqual(HOST_SECTIONS.map((section) => section.key), ['overview', 'roles', 'network', 'settings']);
assert.equal(HOST_PAGES.overview.href, '/operator');
assert.deepEqual(hostSubpages('federation').map((page) => page.key), ['mesh', 'federation']);
assert.deepEqual(hostSubpages('dns').map((page) => page.key), ['operations', 'dns', 'identity', 'versioning', 'moderation']);
assert.deepEqual(hostSubpages('overview'), []);
const reachable = new Set();
for (const section of HOST_SECTIONS) {
    assert.ok(HOST_PAGES[section.page], 'Every section opens a registered host page');
    reachable.add(section.page);
    for (const child of hostSubpages(section.page)) reachable.add(child.key);
}
assert.deepEqual([...reachable].sort(), Object.keys(HOST_PAGES).sort(), 'Every distinct control destination remains reachable from the host entry');
assert.equal(new Set(Object.values(HOST_PAGES).map((page) => page.href)).size, Object.keys(HOST_PAGES).length, 'No duplicate overview destinations');
console.log('Host navigation preserves all control destinations under one overview.');
