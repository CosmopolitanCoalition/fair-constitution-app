// node --experimental-vm-modules --test tests/js/i18nWiring2_fix_boardroom_read.test.mjs
//
// Gap lane fix-boardroom-read pin (operator ruling 2026-09-15, rubric
// boardroom-page-access answer B). A public body's board reads for every
// resident; the server prop canJoin gates the call join and compose form, and
// a non-member sees a read-only notice. The Institution SFC is compiled so a
// template or script error fails the run, and the new i18n key must resolve.
import assert from 'node:assert/strict';
import { readFile as readFileP } from 'node:fs/promises';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const url = rel => new URL(rel, import.meta.url);

test('Institution.vue compiles and wires the server canJoin gate', async () => {
    const source = await readFileP(url('../../resources/js/Pages/Rooms/Institution.vue'), 'utf8');
    const { descriptor } = parse(source, { filename: 'Institution.vue' });
    // Script and template compile without error (throws on any SFC error).
    const script = compileScript(descriptor, { id: 'institution-room' });
    compileTemplate({ source: descriptor.template.content, filename: 'Institution.vue', id: 'institution-room' });

    // The server prop is declared and used as an additional join gate.
    assert.match(script.content, /canJoin:\s*\{\s*type:\s*Boolean/, 'canJoin prop declared');
    assert.match(script.content, /mayJoin\s*=\s*computed\(\(\)\s*=>\s*props\.canJoin\s*&&/, 'mayJoin gates on props.canJoin');

    const tpl = descriptor.template.content;
    assert.match(tpl, /v-if="mayJoin"/, 'LiveRoom and compose form gate on mayJoin');
    assert.doesNotMatch(tpl, /v-if="canJoin"/, 'the raw computed name no longer gates the template');
    assert.match(tpl, /canJoin === false/, 'the read-only notice shows when canJoin is false');
    assert.match(tpl, /institution\.read_only_public_body/, 'the notice reads the new key');
});

test('the read_only_public_body key resolves in the en c_rooms catalog', async () => {
    const raw = await readFileP(url('../../resources/js/i18n/locales/en/c_rooms.json'), 'utf8');
    const dict = JSON.parse(raw);
    const key = 'institution.read_only_public_body';
    assert.ok(Object.prototype.hasOwnProperty.call(dict, key), 'key present');
    assert.match(dict[key], /public body/i, 'key carries the public-body notice text');
    const keys = Object.keys(dict);
    assert.deepEqual(keys, [...keys].sort(), 'catalog keys stay sorted');
});
