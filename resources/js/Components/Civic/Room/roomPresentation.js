// Presentation only. Identity and office assignment remain server-owned.
export const ROOM_LAYOUTS = {
    legislature: { title: 'Legislative chamber', zones: [['dais', 'Speaker’s dais'], ['floor', 'Speaking well'], ['members', 'Members’ rotunda'], ['gallery', 'Public gallery']] },
    court: { title: 'Courtroom', zones: [['dais', 'Judges’ bench'], ['floor', 'Witness stand'], ['counsel', 'Counsel tables'], ['jury', 'Jury seats'], ['gallery', 'Public gallery']] },
    committee: { title: 'Committee room', zones: [['dais', 'Chair’s seat'], ['members', 'Committee table'], ['floor', 'Testimony desk'], ['gallery', 'Public gallery']] },
    board: { title: 'Boardroom', zones: [['dais', 'Chair’s seat'], ['members', 'Board table'], ['floor', 'Presenter’s place'], ['gallery', 'Guests']] },
    commons: { title: 'Meeting room', zones: [['floor', 'Conversation circle']] },
};

export function roomKind(variant) {
    if (['chamber', 'session', 'legislature'].includes(variant)) return 'legislature';
    if (['case', 'judiciary', 'court'].includes(variant)) return 'court';
    return Object.hasOwn(ROOM_LAYOUTS, variant) ? variant : 'commons';
}

export function personLabel(person = {}) {
    const identity = String(person.identity ?? person.handle ?? person.sender ?? '');
    const chosen = String(person.display_name ?? person.displayName ?? '').trim();
    if (chosen && chosen !== identity) return chosen;
    const local = identity.replace(/^@/, '').split(':')[0];
    if (local && !/^u[-_]?[a-f0-9]{6,}$/i.test(local)) return local.replace(/^u-/, '');
    return local ? `Participant ${local.replace(/^u[-_]?/, '').slice(0, 6)}` : 'Unassigned seat';
}

export function personInitials(person) {
    const words = personLabel(person).split(/\s+/u).filter(Boolean);
    return words.length > 1
        ? (Array.from(words[0])[0] + Array.from(words.at(-1))[0]).toLocaleUpperCase()
        : Array.from(words[0] ?? '?').slice(0, 2).join('').toLocaleUpperCase();
}

// Media tracks attach in the visible tiles. Never hide a connected participant:
// collapsing an assigned roster must not detach someone's audio mid-meeting.
export function visibleRoomPeople(people, expanded = false) {
    return expanded ? people : people.filter((person, index) => index < 12 || person.inCall || person.holdsFloor);
}

function zoneFor(kind, person, floorHolder, activeWitness) {
    const role = person.role ?? '';
    if (kind === 'commons') return 'floor';
    if (kind === 'court' && person.identity === activeWitness) return 'floor';
    if (['chair', 'speaker', 'presiding_judge', 'judge', 'facilitator'].includes(role)) return 'dais';
    if (kind === 'court') {
        if (role === 'witness') return 'floor';
        if (['advocate', 'counsel', 'prosecutor', 'defense', 'claimant', 'respondent'].includes(role)) return 'counsel';
        if (['juror', 'jury'].includes(role)) return 'jury';
        return 'gallery';
    }
    if (person.identity === floorHolder) return 'floor';
    if (['member', 'legislator', 'board_member', 'committee_member', 'alternate'].includes(role)) return 'members';
    return 'gallery';
}

/** Merge by exact opaque identity, never by display name or a shortened Matrix ID.
 * The roster supplies roles. Live media supplies presence, never office authority.
 * A seated member without a call connection remains an assigned seat, not "online".
 */
export function roomSeating({ variant = 'commons', roster = [], participants = [], floorHolder = null, activeWitness = null } = {}) {
    const kind = roomKind(variant);
    const people = new Map();
    roster.forEach((person, index) => {
        const identity = person.identity ?? person.handle ?? `seat-${index}`;
        people.set(identity, { ...person, identity, inCall: false, isSpeaking: false });
    });
    participants.forEach((participant) => {
        const seat = people.get(participant.identity);
        people.set(participant.identity, {
            ...participant, ...seat,
            // Copy only observed media state; a call participant cannot claim an office.
            identity: participant.identity, inCall: true, isLocal: participant.isLocal,
            isSpeaking: participant.isSpeaking, audioTrack: participant.audioTrack,
            videoTrack: participant.videoTrack, screenTrack: participant.screenTrack,
            screenAudioTrack: participant.screenAudioTrack,
            display_name: seat?.display_name || participant.display_name || participant.displayName,
            role: seat?.role ?? 'guest',
        });
    });
    if (floorHolder && !people.has(floorHolder)) {
        people.set(floorHolder, { identity: floorHolder, role: 'guest', inCall: false });
    }
    if (kind === 'court' && activeWitness && !people.has(activeWitness)) {
        people.set(activeWitness, { identity: activeWitness, role: 'guest', inCall: false });
    }
    return ROOM_LAYOUTS[kind].zones.map(([id, label]) => ({
        id, label, people: [...people.values()]
            .filter((person) => zoneFor(kind, person, floorHolder, activeWitness) === id)
            .map((person) => ({ ...person, holdsFloor: person.identity === floorHolder })),
    }));
}
