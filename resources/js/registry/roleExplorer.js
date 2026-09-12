// These are reading perspectives, never credentials or invented officeholders.
// Place destinations come from the server and may be absent in a forming world.
export const CIVIC_ROLES = [
    { id: 'resident', title: 'Resident', icon: 'home', description: 'Follow decisions in your community, meet your neighbors, and take part in elections.', destinations: ['rooms', 'election', 'place'], lesson: '/learn', room: null },
    { id: 'legislator', title: 'Legislator', icon: 'landmark', description: 'Follow a bill from proposal through debate, a recorded vote, and enactment.', destinations: ['chamber', 'bills', 'session'], lesson: '/learn/legislature', room: 'legislature' },
    { id: 'speaker', title: 'Speaker', icon: 'message-square', description: 'Organize the agenda, recognize speakers, and keep the chamber moving through its business.', destinations: ['session', 'speaker', 'chamber'], lesson: '/learn/legislature', room: 'legislature' },
    { id: 'chair', title: 'Committee chair', icon: 'users', description: 'Bring evidence into the discussion, hear testimony, and prepare the committee’s report.', destinations: ['committees', 'halls', 'bills'], lesson: '/learn/legislature', room: 'committee' },
    { id: 'judge', title: 'Judge', icon: 'scale', description: 'Read a case, follow the hearing, and see how evidence and constitutional constraints shape a decision.', destinations: ['court', 'docket'], lesson: '/learn/judiciary', room: 'court' },
    { id: 'election-board', title: 'Election board member', icon: 'vote', description: 'Inspect legislative maps, follow candidates and ballots, and review the certification of an election.', destinations: ['board', 'districts', 'election'], lesson: '/learn/election_board', room: 'board' },
    { id: 'worker', title: 'Worker & organizer', icon: 'briefcase', description: 'Find work, exchange goods and services, and take part in the organizations you help run.', destinations: ['market', 'organizations', 'wallet'], lesson: '/learn/guides', room: 'board' },
    { id: 'operator', title: 'Instance operator', icon: 'globe', description: 'Explore the responsibilities of hosting a world: founding, identity, communications, and keeping the service available.', destinations: ['operator', 'operator-roles', 'places'], lesson: '/learn/guides', room: null },
];

export const EXPLORER_LINKS = {
    rooms: 'Enter the live square', halls: 'Visit live halls', election: 'Follow an election', place: 'Open place overview',
    chamber: 'Enter the chamber', bills: 'Read and follow bills', session: 'Open the session workspace', speaker: 'Open the speaker workspace',
    committees: 'Explore committees & hearings', court: 'Visit the courts', docket: 'Read the case docket',
    board: 'Open the election board', districts: 'Walk the legislative maps', market: 'Browse work & trade',
    organizations: 'Explore organizations', wallet: 'Open my wallet', operator: 'View instance operations',
    'operator-roles': 'Explore hosting roles', places: 'Browse all places',
};

export const EXPLORER_GLOBAL_LINKS = {
    market: '/economy/market', organizations: '/organizations', wallet: '/economy/wallet',
    operator: '/operator', 'operator-roles': '/operator/roles', places: '/jurisdictions',
};
