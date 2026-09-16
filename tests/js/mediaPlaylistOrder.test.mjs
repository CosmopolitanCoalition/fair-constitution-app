// node --experimental-vm-modules --test tests/js/mediaPlaylistOrder.test.mjs
//
// W-0448 — the library order is the operator's playlist ("Multilingual Video
// Playlist.xspf", 2026-09-16). resources/js/registry/media.playlist.json is the
// committed order + duration per film; scripts/i18n/build_media_registry.mjs
// orders the generated registry by it. This pin is DB-free: it reads the
// playlist file, the generated client registry and the generated PHP registry
// and asserts the three agree, so a regeneration that drops the order fails
// here rather than on the page.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const playlist = JSON.parse(readFileSync(path.join(root, 'resources/js/registry/media.playlist.json'), 'utf8'));

test('the playlist names every subject once with a positive duration', () => {
    assert.ok(Array.isArray(playlist) && playlist.length >= 61, 'the playlist holds the 61 films');
    const subjects = playlist.map((p) => p.subject);
    assert.equal(new Set(subjects).size, subjects.length, 'no subject repeats');
    for (const p of playlist) {
        assert.equal(typeof p.subject, 'string');
        assert.ok(p.seconds > 0, `${p.subject} carries a duration`);
    }
    assert.equal(subjects[0], 'Summary', 'the playlist opens on Summary');
});

test('the generated client registry follows the playlist order and durations', async () => {
    const mod = await import(pathToFileURL(path.join(root, 'resources/js/registry/media.js')).href);
    const videos = mod.MEDIA_VIDEOS ?? mod.VIDEOS ?? mod.default?.videos;
    assert.ok(Array.isArray(videos), 'media.js exports the video list');
    const order = videos.map((v) => v.subject);
    assert.deepEqual(order.slice(0, playlist.length), playlist.map((p) => p.subject));
    for (const v of videos) {
        const p = playlist.find((x) => x.subject === v.subject);
        if (p) assert.ok(Math.abs(v.seconds - p.seconds) < 1, `${v.subject} duration (manifest may refine the playlist by less than a second)`);
    }
});

test('the generated PHP registry follows the same order', () => {
    const php = readFileSync(path.join(root, 'config/cga/media.php'), 'utf8');
    const subjects = [...php.matchAll(/'subject' => '((?:[^'\\]|\\.)+)'/g)].map((m) => m[1].replace(/\\'/g, "'"));
    assert.deepEqual(subjects.slice(0, playlist.length), playlist.map((p) => p.subject));
});
