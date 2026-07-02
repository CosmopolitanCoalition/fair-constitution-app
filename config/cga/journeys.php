<?php

/*
|--------------------------------------------------------------------------
| CGA journey registry (mockups-v3-wiring Phase 3c)
|--------------------------------------------------------------------------
|
| THE server-side validation source for the journeys engine: which journeys
| exist, their step arcs, and whether they are live yet. Transcribed
| faithfully from the design contract — mockups/v3/assets/js/fixtures-v2.js
| `journeys` (rail => steps; 'built-layer' => live, 'planned-layer' =>
| planned). The client rendering mirror is resources/js/registry/journeys.js
| — keep config, registry, and the mockup fixtures in sync.
|
| Record shape:
|   id (key)  string(64) — the durable journey id stored on progress rows
|             and denormalized onto achievements
|   title     denormalized onto the achievement row at earn time
|   steps     the arc, in order (0-based indexes are what journey_progress
|             stores in steps_done)
|   status    'live' | 'planned' — planned journeys reject step marking
|   cls       interaction class (§7 honest map) — display grouping only
*/

return [

    'election' => [
        'title'  => 'An election, end to end',
        'steps'  => ['Approval', 'Candidate forum', 'Finalist cutoff', 'Ranked vote', 'Count', 'Seated', 'First session'],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'committee-session' => [
        'title'  => 'A committee session, live',
        'steps'  => ['Convene', 'Quorum', 'Agenda', 'Testimony', 'Motion', 'Committee vote', 'Report'],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'bill' => [
        'title'  => 'A bill becomes law',
        'steps'  => ['Introduced', 'Committee', 'Floor reading', 'Floor vote', 'Enacted', 'Published'],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'court-case' => [
        'title'  => 'A court case, end to end',
        'steps'  => ['Filed', 'Panel', 'Hearings', 'Evidence', 'Jury', 'Arguments', 'Deliberation', 'Judgment', 'Opinion'],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'budget' => [
        'title'  => 'Enacting a budget',
        'steps'  => ['Revenue', 'Budget bill', 'Appropriations', 'Disbursement', 'Ledger'],
        'status' => 'planned',
        'cls'    => 'gov-itself',
    ],

    'start-org' => [
        'title'  => 'Starting an organization',
        'steps'  => ['Register', 'Charter', 'First board', 'Onboard', 'Market (opt.)'],
        'status' => 'live',
        'cls'    => 'orgs-people',
    ],

    'board-meeting' => [
        'title'  => 'Holding a board meeting',
        'steps'  => ['Convene', 'Composition', 'Motions', 'Board vote', 'Minutes'],
        'status' => 'live',
        'cls'    => 'orgs-people',
    ],

    'form-a-group' => [
        'title'  => 'An informal group forms and meets',
        'steps'  => ['Create', 'Discuss', 'Call a meeting', 'Decide', 'Next steps (opt.)'],
        'status' => 'live',
        'cls'    => 'people',
    ],

    'mutual-aid' => [
        'title'  => 'Asking for and giving help',
        'steps'  => ['Post request', 'A neighbor responds', 'Coordinate', 'Resolved'],
        'status' => 'planned',
        'cls'    => 'people',
    ],

    'petition-to-referendum' => [
        'title'  => 'From a petition to a referendum',
        'steps'  => ['Petition', 'Signatures', 'Reaches legislature', 'Referendum', 'Town hall', 'Vote', 'Result'],
        'status' => 'live',
        'cls'    => 'gov-orgs-people',
    ],

    'public-service' => [
        'title'  => 'A government creates a public service',
        'steps'  => ['Charter CGC', 'Board of Governors', 'Serves the public', 'Monopoly path (opt.)'],
        'status' => 'live',
        'cls'    => 'gov-orgs-people',
    ],

    'stipend-and-tax' => [
        'title'  => 'The money between a person and their government',
        'steps'  => ['Stipend run', 'Your receipt', 'Tax filing', 'Public ledger'],
        'status' => 'planned',
        'cls'    => 'gov-orgs-people',
    ],

    'two-governments' => [
        'title'  => 'Two governments meet, trade, and merge',
        'steps'  => ['Discover a peer', 'Trust each other’s records', 'Trade talks', 'Union or border'],
        'status' => 'live',
        'cls'    => 'gov-gov',
    ],

];
