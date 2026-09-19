// node --experimental-vm-modules --test tests/js/simCeiling.test.mjs
//
// THE CEILING LAW (operator ruling 2026-09-19, rubric sim-roster-vs-real-population
// = C, clarified "ceiling at real population"). The simulation never mints more
// people than a place has real residents, and zero is zero. A place with zero
// population, or with fewer residents than its election needs, closes DONE with
// no election. Both are counted on the run and shown on the Step 5 page.
//
// SOURCE pins (DB-free). The behaviour itself is pinned on live PostgreSQL in
// IdentityStageTest, ElectionStageTest and (sqlite) WorldReadinessGuardTest.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (...seg) => readFileSync(path.join(root, ...seg), 'utf8');
const stage = (file) => read('app', 'Services', 'Demo', 'Stages', file);

test('the identity stage caps the roster at the real population, after it sizes the want', () => {
    const src = stage('IdentityStage.php');
    const want = src.indexOf('$wanted = max(self::rosterSize($jurisdictionId), $popTarget, $minFloor);');
    const cap = src.indexOf('$needed = self::cappedRoster($wanted, $population);');
    const existing = src.indexOf("if ($existing >= $needed)");
    assert.ok(want > 0 && cap > want && existing > cap, 'want, then the ceiling, then the top-up test');
    assert.match(src, /return max\(0, min\(\$wanted, \$population\)\);/);
});

test('zero is zero: an empty place returns before the race-plan walk', () => {
    const src = stage('IdentityStage.php');
    const zero = src.indexOf('if ($population === 0) {');
    const roster = src.indexOf('self::rosterSize($jurisdictionId), $popTarget');
    assert.ok(zero > 0 && zero < roster, 'the zero return comes first');
    assert.match(src, /'inactive' => self::INACTIVE_ZERO_POPULATION/);
});

test('the election stage closes a short-by-law election and still reviews a real defect', () => {
    const src = stage('ElectionStage.php');
    assert.match(src, /IdentityStage::populationOf\(\$scope, \$version\)/);
    assert.match(src, /'election_id' => null,\s+'races' => \$races,\s+'candidacies' => 0,\s+'blocked_kinds' => \[IdentityStage::INACTIVE_TOO_FEW_RESIDENTS\]/);
    // The original refusal stays for a scope that HAS the people.
    assert.match(src, /Roster too small to contest this election/);
    // A parent with one tiny constituent is never half-fielded.
    assert.match(src, /count\(\$tooFew\) < count\(\$byScope\)/);
});

test('the verify stage settles a lawful inactive place done', () => {
    const src = stage('VerifyStage.php');
    assert.match(src, /\$inactive = self::lawfulInactive\(\$jurisdictionId, \$runId, \$version\);/);
    assert.ok(src.indexOf('self::lawfulInactive(') < src.indexOf("'legislature has zero seats (chamber not sized)'"));
});

test('both kinds of place are counted on the run and served to the page', () => {
    const job = read('app', 'Jobs', 'SimWorkerJob.php');
    assert.match(job, /\$inc\['places_zero_population'\] = 1;/);
    assert.match(job, /\$inc\['places_too_few_residents'\] = 1;/);
    const snap = read('app', 'Services', 'Demo', 'SimSnapshot.php');
    assert.match(snap, /'places_zero_population' => \$zeroPop/);
    assert.match(snap, /'places_too_few_residents' => \$tooFew/);
    const migration = read('database', 'migrations', '2026_09_19_150000_sim_runs_inactive_counters.php');
    assert.match(migration, /'places_zero_population'/);
    assert.match(migration, /'places_too_few_residents'/);
});

test('the Step 5 page shows the two counts, and the strings are in the en catalogue', () => {
    const page = read('resources', 'js', 'Pages', 'Setup', 'Step5_Simulate.vue');
    assert.match(page, /world\.places_zero_population/);
    assert.match(page, /world\.places_too_few_residents/);
    const cat = JSON.parse(read('resources', 'js', 'i18n', 'locales', 'en', 'c_setup.json'));
    for (const k of [
        'step5_simulate.inactive_heading', 'step5_simulate.inactive_zero_population',
        'step5_simulate.inactive_too_few_residents', 'step5_simulate.inactive_note',
        'step4_scale_up.sim_ceiling_note', 'step4_scale_up.sim_floor_help',
    ]) {
        assert.equal(typeof cat[k], 'string', `missing ${k}`);
    }
    assert.match(cat['step4_scale_up.sim_floor_help'], /never more than its real population/);
});
