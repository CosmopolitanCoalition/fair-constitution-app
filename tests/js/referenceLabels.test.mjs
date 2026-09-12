import assert from 'node:assert/strict';
import { normalizeReference, referenceLabel, readableReferences, referenceCatalog, settingLabel } from '../../resources/js/lib/referenceLabels.js';

assert.equal(referenceLabel('F-EXE001'), 'Board of Governors Nomination');
assert.equal(referenceLabel('WF-EXE04'), 'Department Creation');
assert.equal(referenceLabel('CLK09'), 'Judicial / Civil Officer Term');
assert.equal(referenceLabel('R09'), 'Legislative Representative');
assert.equal(normalizeReference('F-EXE-001'), 'F-EXE-001');
const catalog = referenceCatalog();
for (const [alias, canonical] of Object.entries(catalog.aliases)) {
    assert.equal(referenceLabel(alias), referenceLabel(canonical), `Pure alias ${alias} resolves to its canonical action`);
}
for (const [id, name] of Object.entries(catalog.forms)) {
    assert.equal(referenceLabel(id), name, `Canonical action ${id} is never repurposed by a catalog alias`);
}
for (const [id, name] of Object.entries(catalog.roles)) assert.equal(referenceLabel(id), name);
assert.equal(referenceLabel('F-UNKNOWN999'), 'Civic action', 'Unknown action codes do not leak into player labels');
assert.equal(referenceLabel('R-99'), 'Civic role');
assert.equal(referenceLabel('CLK-07'), 'District maximum seats', 'Legacy clock names never imply a chamber-size limit');
assert.equal(referenceLabel('CLK-08'), 'District minimum seats');
assert.equal(readableReferences('Use F-EXE001 after WF-EXE04; CLK09 applies to R18.'), 'Use Board of Governors Nomination after Department Creation; Judicial / Civil Officer Term applies to Department governor.');
assert.equal(readableReferences('Article III; 2031-10-30; Candidate Ada'), 'Article III; 2031-10-30; Candidate Ada');
assert.equal(referenceLabel('F-EXE001', { translate: (key) => key }), 'c_references.form.F-EXE-001');
assert.equal(settingLabel('legislature_max_seats'), 'District maximum seats');
assert.equal(settingLabel('election_interval_months'), 'Election interval (months)');
assert.equal(settingLabel('example_future_threshold'), 'Example future threshold');
assert.equal(settingLabel('election_interval_months', { name: 'Election frequency', translate: () => 'ignored' }), 'Election frequency', 'Explicit human label remains authoritative');
console.log('Canonical action, process, role, and setting label checks passed.');
