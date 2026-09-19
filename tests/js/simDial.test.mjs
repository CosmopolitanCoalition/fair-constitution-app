// node --experimental-vm-modules --test tests/js/simDial.test.mjs
//
// THE SIMULATION DIAL (operator order 2026-09-19). The Dev simulate checkbox
// moved from the end of Step 2 to the end of Step 4, with a population dial
// (number box + sliding scale, default 0.1 %) and an override checkbox for the
// places the dial would leave unsimulatable. DB-free: the pure dial math, then
// SOURCE pins on the three pages and the server seam.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
    DIAL_DEFAULT, DIAL_MAX, SLIDER_STEPS, SLIDER_LOW_PCT,
    clampPct, oneIn, pctFromSlider, sliderFromPct,
} from '../../resources/js/lib/simDial.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (...seg) => readFileSync(path.join(root, ...seg), 'utf8');
// Page paths are built from segments (NavRoleGateParityTest reads full
// 'Pages/<module>/<file>.vue' literals as DOM-mount companions).
const page = (file) => read('resources', 'js', 'Pages', 'Setup', file);

test('the default dial is 0.1 percent: one resident in a thousand', () => {
    assert.equal(DIAL_DEFAULT, 0.1);
    assert.equal(oneIn(DIAL_DEFAULT), 1000);
    assert.equal(oneIn(1), 100);
    assert.equal(oneIn(0), null);
});

test('the number box clamps to 0..100 and a non-number gives the default', () => {
    assert.equal(clampPct('0.25'), 0.25);
    assert.equal(clampPct(-3), 0);
    assert.equal(clampPct(250), DIAL_MAX);
    assert.equal(clampPct(0.123456), 0.1235);
    assert.equal(clampPct(''), DIAL_DEFAULT);
    assert.equal(clampPct('abc'), DIAL_DEFAULT);
    assert.equal(clampPct(undefined), DIAL_DEFAULT);
});

test('the sliding scale is logarithmic, monotonic, and spans 0.01 to 100', () => {
    assert.equal(pctFromSlider(0), SLIDER_LOW_PCT);
    assert.equal(pctFromSlider(SLIDER_STEPS), DIAL_MAX);
    assert.equal(pctFromSlider(SLIDER_STEPS / 4), 0.1, 'a quarter of the way is the default');
    assert.equal(pctFromSlider(SLIDER_STEPS / 2), 1);

    let last = -1;
    for (let p = 0; p <= SLIDER_STEPS; p++) {
        const v = pctFromSlider(p);
        assert.ok(v >= last, `position ${p} went backwards (${v} < ${last})`);
        assert.ok(v >= SLIDER_LOW_PCT && v <= DIAL_MAX);
        last = v;
    }
});

test('the slider follows the number box', () => {
    assert.equal(sliderFromPct(DIAL_DEFAULT), SLIDER_STEPS / 4);
    assert.equal(sliderFromPct(100), SLIDER_STEPS);
    assert.equal(sliderFromPct(0), 0, 'below the slider low end sits at the left stop');
    assert.equal(sliderFromPct(0.005), 0);
    // A value picked on the slider maps back to the same position.
    for (const p of [0, 40, 100, 200, 333, SLIDER_STEPS]) {
        assert.ok(Math.abs(sliderFromPct(pctFromSlider(p)) - p) <= 2, `position ${p} round-trips`);
    }
});

test('Step 2 no longer carries the Dev simulate checkbox or sends the flag', () => {
    const src = page('Step2_MapData.vue');
    assert.doesNotMatch(src, /simulateAtScale/);
    assert.doesNotMatch(src, /simulate_at_scale/);
    assert.doesNotMatch(src, /is_dev_world/);
    assert.doesNotMatch(src, /step2_map_data\.dev_simulate/);
});

test('Step 4 carries the checkbox, the number box, the sliding scale and the override, sandbox only', () => {
    const src = page('Step4_ScaleUp.vue');
    assert.match(src, /settings\.game_mode === 'sandbox'/);
    assert.match(src, /<section v-if="isDevWorld"/);
    assert.match(src, /type="checkbox" v-model="simulate"/);
    assert.match(src, /id="sim-dial" type="number" min="0" max="100"/);
    assert.match(src, /type="range" min="0" :max="SLIDER_STEPS"/);
    assert.match(src, /type="checkbox" v-model="rosterFloor"/);
    // Defaults: the stored choice, else 0.1 and the override on.
    assert.match(src, /clampPct\(props\.settings\.sim_sample_pct \?\? DIAL_DEFAULT\)/);
    assert.match(src, /props\.settings\.sim_roster_floor !== false/);
});

test('the Step 4 lock sends all three choices', () => {
    const src = page('Step4_ScaleUp.vue');
    const lock = src.slice(src.indexOf('async function lockAndContinue'));
    assert.match(lock, /simulate_at_scale: simulate\.value/);
    assert.match(lock, /sim_sample_pct: dial\.value/);
    assert.match(lock, /sim_roster_floor: rosterFloor\.value/);
    assert.match(lock, /'\/api\/setup\/wizard\/step4\/complete', body/);
});

test('Step 5 shows the dial a run uses', () => {
    const src = page('Step5_Simulate.vue');
    assert.match(src, /step5_simulate\.dial_this_run/);
    assert.match(src, /step5_simulate\.dial_next_run/);
});

test('the server reads the stored dial at the Step 5 start and writes it at the Step 4 lock', () => {
    const ctl = read('app', 'Http', 'Controllers', 'SetupController.php');
    const lock = ctl.slice(ctl.indexOf('public function completeStep4'), ctl.indexOf('public function step5Progress'));
    assert.match(lock, /SimDial::lockWrites\(/);
    assert.ok(lock.indexOf('SimDial::lockWrites(') < lock.indexOf('SetupLadder::completed(4'),
        'the choices are written before the ladder walks');
    const start = ctl.slice(ctl.indexOf('public function step5Start'), ctl.indexOf('public function step5Halt'));
    assert.match(start, /SimDial::startOptions\(\$settings\)/);
});

test('every new string is in the en catalogue', () => {
    const cat = JSON.parse(read('resources', 'js', 'i18n', 'locales', 'en', 'c_setup.json'));
    for (const k of [
        'step4_scale_up.sim_heading', 'step4_scale_up.sim_intro', 'step4_scale_up.sim_dial_label',
        'step4_scale_up.sim_dial_slider_aria', 'step4_scale_up.sim_dial_one_in', 'step4_scale_up.sim_dial_zero',
        'step4_scale_up.sim_dial_cap', 'step4_scale_up.sim_dial_high', 'step4_scale_up.sim_floor_label',
        'step4_scale_up.sim_floor_help', 'step5_simulate.dial_this_run', 'step5_simulate.dial_next_run',
        'step5_simulate.dial_floor_on', 'step5_simulate.dial_floor_off', 'step5_simulate.dial_change',
        'step2_map_data.dev_simulate',
    ]) {
        assert.equal(typeof cat[k], 'string', `missing ${k}`);
        assert.ok(cat[k].length > 0);
    }
});
