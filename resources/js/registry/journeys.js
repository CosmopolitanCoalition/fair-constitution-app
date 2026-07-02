/* ============================================================================
   CGA — registry/journeys.js  (mockups-v3-wiring Phase 3c)

   Mirror of config/cga/journeys.php + mockups fixtures-v2.js — keep the
   three in sync.

   The CLIENT rendering source for the journeys engine: display data only
   (titles, interaction-class grouping, arcs, rooms, your-part copy, earn
   copy). The SERVER validation source is config/cga/journeys.php — step
   marking is validated there, never here. status: 'live' | 'planned'
   ('built-layer' / 'planned-layer' in the mockup fixtures).

   Soft-gate rule: journeys nudge, they NEVER block. A medal never changes
   a vote, a seat, or what you are allowed to do.
   ============================================================================ */

/* Interaction classes (§7 honest map) in display order — grouping labels. */
export const CLASSES = [
    { id: 'people', label: 'People, together' },
    { id: 'orgs-people', label: 'Organizations & people' },
    { id: 'gov-itself', label: 'A government with itself' },
    { id: 'gov-gov', label: 'Governments with each other' },
    { id: 'gov-orgs-people', label: 'Government with organizations & people' },
];

export const CLASS_LABELS = Object.fromEntries(CLASSES.map((c) => [c.id, c.label]));

export const JOURNEYS = [
    {
        id: 'election', cls: 'gov-itself', clsLabel: CLASS_LABELS['gov-itself'],
        flagship: true, status: 'live',
        title: 'An election, end to end',
        yourPart: 'you vote this one — approve the candidates you trust, then rank them when the window opens',
        rail: ['Approval', 'Candidate forum', 'Finalist cutoff', 'Ranked vote', 'Count', 'Seated', 'First session'],
        rooms: ['Candidate forum'],
        earn: 'the candidate forum will greet you as someone who knows the ropes',
    },
    {
        id: 'committee-session', cls: 'gov-itself', clsLabel: CLASS_LABELS['gov-itself'],
        flagship: false, status: 'live',
        title: 'A committee session, live',
        yourPart: null,
        rail: ['Convene', 'Quorum', 'Agenda', 'Testimony', 'Motion', 'Committee vote', 'Report'],
        rooms: ['Committee hearing'],
        earn: 'the committee hearing will greet you as someone who knows the ropes',
    },
    {
        id: 'bill', cls: 'gov-itself', clsLabel: CLASS_LABELS['gov-itself'],
        flagship: false, status: 'live',
        title: 'A bill becomes law',
        yourPart: null,
        rail: ['Introduced', 'Committee', 'Floor reading', 'Floor vote', 'Enacted', 'Published'],
        rooms: ['Legislative session'],
        earn: 'the legislative session will greet you as someone who knows the ropes',
    },
    {
        id: 'court-case', cls: 'gov-itself', clsLabel: CLASS_LABELS['gov-itself'],
        flagship: false, status: 'live',
        title: 'A court case, end to end',
        yourPart: null,
        rail: ['Filed', 'Panel', 'Hearings', 'Evidence', 'Jury', 'Arguments', 'Deliberation', 'Judgment', 'Opinion'],
        rooms: ['Court hearing'],
        earn: 'the court hearing will greet you as someone who knows the ropes',
    },
    {
        id: 'budget', cls: 'gov-itself', clsLabel: CLASS_LABELS['gov-itself'],
        flagship: false, status: 'planned', phase: 'Phase L',
        title: 'Enacting a budget',
        yourPart: null,
        rail: ['Revenue', 'Budget bill', 'Appropriations', 'Disbursement', 'Ledger'],
        rooms: ['Legislative session'],
        earn: 'the legislative session will greet you as someone who knows the ropes',
    },
    {
        id: 'start-org', cls: 'orgs-people', clsLabel: CLASS_LABELS['orgs-people'],
        flagship: false, status: 'live',
        title: 'Starting an organization',
        yourPart: 'you do this one — register the organization, write the charter, and seat the first board',
        rail: ['Register', 'Charter', 'First board', 'Onboard', 'Market (opt.)'],
        rooms: ['Board meeting'],
        earn: 'the board meeting will greet you as someone who knows the ropes',
    },
    {
        id: 'board-meeting', cls: 'orgs-people', clsLabel: CLASS_LABELS['orgs-people'],
        flagship: false, status: 'live',
        title: 'Holding a board meeting',
        yourPart: null,
        rail: ['Convene', 'Composition', 'Motions', 'Board vote', 'Minutes'],
        rooms: ['Board meeting'],
        earn: 'the board meeting will greet you as someone who knows the ropes',
    },
    {
        id: 'form-a-group', cls: 'people', clsLabel: CLASS_LABELS.people,
        flagship: false, status: 'live',
        title: 'An informal group forms and meets',
        yourPart: 'you do this one — start the group, invite your neighbours, and call the first meeting',
        rail: ['Create', 'Discuss', 'Call a meeting', 'Decide', 'Next steps (opt.)'],
        rooms: ['Group meeting'],
        earn: 'the group meeting will greet you as someone who knows the ropes',
    },
    {
        id: 'mutual-aid', cls: 'people', clsLabel: CLASS_LABELS.people,
        flagship: false, status: 'planned', phase: 'Phase M',
        title: 'Asking for and giving help',
        yourPart: 'you do this one — post a request for help, or answer a neighbour’s',
        rail: ['Post request', 'A neighbor responds', 'Coordinate', 'Resolved'],
        rooms: ['Group meeting'],
        earn: 'the group meeting will greet you as someone who knows the ropes',
    },
    {
        id: 'petition-to-referendum', cls: 'gov-orgs-people', clsLabel: CLASS_LABELS['gov-orgs-people'],
        flagship: false, status: 'live',
        title: 'From a petition to a referendum',
        yourPart: 'you do this one — sign (or start) the petition, then vote when the referendum opens',
        rail: ['Petition', 'Signatures', 'Reaches legislature', 'Referendum', 'Town hall', 'Vote', 'Result'],
        rooms: ['Referendum town hall'],
        earn: 'the referendum town hall will greet you as someone who knows the ropes',
    },
    {
        id: 'public-service', cls: 'gov-orgs-people', clsLabel: CLASS_LABELS['gov-orgs-people'],
        flagship: false, status: 'live',
        title: 'A government creates a public service',
        yourPart: null,
        rail: ['Charter CGC', 'Board of Governors', 'Serves the public', 'Monopoly path (opt.)'],
        rooms: ['Legislative session'],
        earn: 'the legislative session will greet you as someone who knows the ropes',
    },
    {
        id: 'stipend-and-tax', cls: 'gov-orgs-people', clsLabel: CLASS_LABELS['gov-orgs-people'],
        flagship: false, status: 'planned', phase: 'Phase L/M',
        title: 'The money between a person and their government',
        yourPart: 'this one comes to you — the stipend lands in your wallet, and you file the tax side yourself',
        rail: ['Stipend run', 'Your receipt', 'Tax filing', 'Public ledger'],
        rooms: [],
        earn: 'the places this journey touches will greet you as someone who knows the ropes',
    },
    {
        id: 'two-governments', cls: 'gov-gov', clsLabel: CLASS_LABELS['gov-gov'],
        flagship: false, status: 'live',
        title: 'Two governments meet, trade, and merge',
        yourPart: null,
        rail: ['Discover a peer', 'Trust each other’s records', 'Trade talks', 'Union or border'],
        rooms: ['Referendum town hall'],
        earn: 'the referendum town hall will greet you as someone who knows the ropes',
    },
];

export const JOURNEYS_BY_ID = Object.fromEntries(JOURNEYS.map((j) => [j.id, j]));

/* Generic your-part fallback (mockup journey.html yourPart derivation). */
export function yourPartFor(journey) {
    if (!journey) return 'follow the arc below';
    if (journey.yourPart) return journey.yourPart;
    return (journey.rooms || []).length
        ? 'follow the arc below — watch from the gallery, or take the floor where you’re a resident'
        : 'follow the arc below';
}
