import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { roomSeating, personLabel } from '../../resources/js/Components/Civic/Room/roomPresentation.js';

// The PowerShell runner generates these props through the real PHP room controllers.
// Deliberately outside tests/js: requires the companion private PHP fixture, never silently skips.
const nonce = process.env.ROOM_WORKFLOW_SNAPSHOT_ID;
assert.match(nonce ?? '', /^[0-9a-f]{32}$/, 'Run tests/rooms/run-workflow.ps1 to create a private controller snapshot.');
const fixture = JSON.parse(await readFile(new URL(`../../storage/framework/testing/r2-room-workflow-${nonce}.json`, import.meta.url), 'utf8'));
assert.equal(fixture.fixture, 'room-workflow-private-v1');
const snapshots = fixture.snapshots;
const identity = name => `@u-${name}:room-workflow.invalid`;
const tracks = new Map();
function media(snapshot, replacements = {}) {
    return snapshot.connected.map(handle => {
        if (!tracks.has(handle)) tracks.set(handle, { audioTrack: { id: `synthetic-audio-${handle}` }, videoTrack: { id: `synthetic-video-${handle}` } });
        return { identity: handle, ...tracks.get(handle), ...replacements[handle], role: 'speaker', isSpeaking: handle === snapshot.floorHolder };
    });
}
const seating = (name, replacements) => {
    const snapshot = snapshots[name];
    for (const row of snapshot.connectedRoster) {
        const preview = snapshot.roster.find(person => person.handle === row.handle);
        assert.equal(row.role, preview?.role); assert.equal(row.display_name, preview?.display_name);
    }
    return roomSeating({ ...snapshot, participants: media(snapshot, replacements) });
};
const one = (zones, name) => {
    const matching = zones.flatMap(zone => zone.people.map(person => ({ ...person, zone: zone.id }))).filter(person => person.identity === identity(name));
    assert.equal(matching.length, 1, `${name} occupies exactly one place`); return matching[0];
};

test('controller recognition moves a member and the same synthetic tracks into the speaking well', () => {
    const zones = seating('chamber_speaking');
    assert.equal(one(zones, 'speaker').zone, 'dais');
    const member = one(zones, 'member'); assert.equal(member.zone, 'floor'); assert.equal(member.holdsFloor, true);
    assert.equal(member.audioTrack, tracks.get(identity('member')).audioTrack); assert.equal(member.videoTrack, tracks.get(identity('member')).videoTrack);
    assert.equal(personLabel(member), 'Public member');
});

test('actual court floor props keep the witness at the stand while the judge speaks', () => {
    const first = one(seating('court_witness'), 'witness'); assert.equal(first.zone, 'floor');
    const questioning = seating('court_questioning'); const witness = one(questioning, 'witness');
    assert.equal(witness.zone, 'floor'); assert.equal(witness.holdsFloor, false); assert.equal(witness.videoTrack, first.videoTrack);
    const judge = one(questioning, 'judge'); assert.equal(judge.zone, 'dais'); assert.equal(judge.holdsFloor, true);
    assert.equal(personLabel(witness), 'Public witness'); assert.equal(witness.role, 'claimant', 'Ephemeral positioning never invents a judicial office.');
});

test('disconnection leaves an offline position and rejoin attaches only the replacement tracks to it', () => {
    const disconnected = one(seating('court_disconnected'), 'witness');
    assert.equal(disconnected.zone, 'floor'); assert.equal(disconnected.inCall, false);
    assert.equal(personLabel(disconnected), 'Public witness');
    assert.equal(disconnected.audioTrack, undefined); assert.equal(disconnected.videoTrack, undefined);
    const replacements = { audioTrack: { id: 'synthetic-rejoin-audio' }, videoTrack: { id: 'synthetic-rejoin-video' } };
    const rejoined = one(seating('court_rejoined', { [identity('witness')]: replacements }), 'witness');
    assert.equal(rejoined.inCall, true); assert.equal(rejoined.zone, 'floor'); assert.equal(rejoined.audioTrack, replacements.audioTrack);
    assert.equal(rejoined.videoTrack, replacements.videoTrack); assert.equal(personLabel(rejoined), 'Public witness');
    assert.equal(one(seating('court_yielded'), 'witness').zone, 'counsel');
});

test('private-board props place the real chair and recognized member without accepting a media role claim', () => {
    const zones = seating('board_speaking');
    assert.equal(one(zones, 'chair').zone, 'dais'); const director = one(zones, 'director');
    assert.equal(director.zone, 'floor'); assert.equal(director.role, 'board_member'); assert.equal(personLabel(director), 'Public director');
    assert.equal(zones.flatMap(zone => zone.people).length, 2);
});
