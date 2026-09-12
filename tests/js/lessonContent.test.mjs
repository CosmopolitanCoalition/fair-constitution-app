import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { lessonContentFor } from '../../resources/js/composables/lessonContent.js';
import { EDUCATION_BY_SURFACE } from '../../resources/js/registry/education.js';

const curriculum = readFileSync(new URL('../../config/cga/education.php', import.meta.url), 'utf8');
const surfaces = [...curriculum.matchAll(/'surface_id'\s*=>\s*'([^']+)'/g)].map(match => match[1]);
const messages = JSON.parse(readFileSync(new URL('../../resources/js/i18n/locales/en/c_education.json', import.meta.url), 'utf8'));

test('every seeded curriculum has readable teaching content before its quiz', () => {
    assert.ok(surfaces.length > 0, 'the real curriculum must be checked');
    for (const surface of surfaces) {
        const lesson = lessonContentFor(surface);
        assert.ok(lesson, `Missing lesson body for ${surface}`);
        assert.ok(lesson.steps.length > 0, `Missing teaching steps for ${surface}`);
        const keys = [lesson.learn, ...lesson.steps.flatMap(step => [step.do, step.detail]), lesson.why].filter(Boolean);
        for (const key of keys) {
            assert.equal(typeof messages[key.replace(/^c_education\./, '')], 'string', `Untranslated teaching text: ${key}`);
        }
    }
});

test('existing election-board curriculum resolves to the authored board lesson', () => {
    assert.equal(lessonContentFor('elections/board'), EDUCATION_BY_SURFACE['elections/board-console']);
});

test('current IDs pass through and missing content remains an explicit empty state', () => {
    assert.equal(lessonContentFor('executive/departments'), EDUCATION_BY_SURFACE['executive/departments']);
    assert.equal(lessonContentFor('future/unknown'), null);
    assert.equal(lessonContentFor(null), null);
});
