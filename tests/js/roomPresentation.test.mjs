import assert from 'node:assert/strict';
import { personLabel, personInitials, roomSeating, visibleRoomPeople } from '../../resources/js/Components/Civic/Room/roomPresentation.js';

const id = '@u-123456abcdef:local.test';
assert.equal(personLabel({ identity: id, display_name: 'Ada Example' }), 'Ada Example');
assert.equal(personLabel({ identity: id, name: 'Private legal name', email: 'private@example.test' }), 'Participant 123456');
assert.equal(personLabel({ identity: id, displayName: id }), 'Participant 123456');
assert.equal(personLabel({ identity: '@u-river:remote.test' }), 'river');
assert.equal(personLabel({ identity: id, display_name: '@My chosen name' }), '@My chosen name');
assert.equal(personInitials({ display_name: 'Łucja Żak' }), 'ŁŻ');

const roster = [
    { handle: 'chair', role: 'chair', display_name: 'Chairperson' },
    { handle: 'member', role: 'member', display_name: 'Member' },
];
const seating = roomSeating({ variant: 'committee', roster, floorHolder: 'member', participants: [{ identity: 'member', isSpeaking: true, audioTrack: { id: 'audio' } }] });
assert.equal(seating.find((zone) => zone.id === 'dais').people[0].inCall, false, 'assigned chair is not marked present');
const speaking = seating.find((zone) => zone.id === 'floor').people;
assert.equal(speaking.length, 1, 'recognized member has one seat');
assert.equal(speaking[0].inCall, true);
assert.equal(speaking[0].audioTrack.id, 'audio', 'real track follows the recognized member');
assert.equal(speaking[0].display_name, 'Member');
assert.equal(seating.find((zone) => zone.id === 'members').people.length, 0, 'no duplicate audio at original seat');

const court = roomSeating({ variant: 'court', roster: [
    { identity: 'judge', role: 'judge' }, { identity: 'witness', role: 'witness' },
    { identity: 'advocate', role: 'advocate' }, { identity: 'juror', role: 'juror' },
], participants: [{ identity: 'visitor', role: 'judge' }] });
assert.deepEqual(court.map((zone) => [zone.id, zone.people.map((person) => person.identity)]), [
    ['dais', ['judge']], ['floor', ['witness']], ['counsel', ['advocate']], ['jury', ['juror']], ['gallery', ['visitor']],
], 'a participant-supplied role cannot take a judge seat');

const collision = roomSeating({ variant: 'legislature', roster: [{ identity: '@alex:one.test', role: 'speaker', display_name: 'Alex' }], participants: [{ identity: '@alex:two.test', display_name: 'Alex' }] });
assert.equal(collision.find((zone) => zone.id === 'dais').people[0].inCall, false, 'equal names/localparts across servers never merge');
assert.equal(collision.find((zone) => zone.id === 'gallery').people[0].identity, '@alex:two.test');
assert.equal(roomSeating({ variant: 'board', roster: [{ identity: 'director', role: 'board_member' }] }).find((zone) => zone.id === 'members').people.length, 1);
assert.equal(roomSeating({ variant: 'court' }).flatMap((zone) => zone.people).length, 0, 'empty preview invents no occupants');
assert.equal(roster[1].identity, undefined, 'presentation does not mutate server roster');
const largeRoster = Array.from({ length: 30 }, (_, index) => ({ identity: String(index), inCall: index === 24, holdsFloor: index === 29 }));
assert.equal(visibleRoomPeople(largeRoster).length, 14, 'only disconnected assigned seats may collapse');
assert.ok(visibleRoomPeople(largeRoster).some((person) => person.identity === '24'), 'participant audio stays mounted beyond the visual seat limit');
assert.ok(visibleRoomPeople(largeRoster).some((person) => person.identity === '29'), 'recognized speaker remains visible');
assert.equal(visibleRoomPeople(largeRoster, true).length, 30);
console.log('Room seating and public-name checks passed.');
