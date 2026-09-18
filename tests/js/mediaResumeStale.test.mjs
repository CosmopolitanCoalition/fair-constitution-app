// node --experimental-vm-modules --test tests/js/mediaResumeStale.test.mjs
//
// W-0448 stale-claim reclaim, the frontend half of the escape-hatch law
// (operator order 2026-09-18): the Step 2 Resume control must be reachable on a
// RUNNING run that holds stalled items (a lane killed mid-file), not only on a
// halted run. DB-free: reads the .vue source and compiles it.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const file = path.join(root, 'resources/js/Pages/Setup/Step2_MapData.vue');
const source = readFileSync(file, 'utf8');

test('the Resume control shows on a halted run and on a running run with stalled items', () => {
    const button = source.match(/<button v-if="([^"]+)"[^>]*@click="mediaControl\('resume'\)"/);
    assert.ok(button, 'the Resume button is present');
    const cond = button[1];
    assert.match(cond, /mediaPull\.status === 'halted'/);
    assert.match(cond, /mediaPull\.status === 'running' && mediaPull\.items_stale > 0/);
});

test('the page still compiles', () => {
    const { descriptor, errors } = parse(source, { filename: file });
    assert.deepEqual(errors, []);
    const out = compileTemplate({ source: descriptor.template.content, filename: file, id: 'step2' });
    assert.deepEqual(out.errors, []);
});
