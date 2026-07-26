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

    /*
     * The first arc anyone walks, added 2026-07-26 after lane 6 found the gap
     * while building the playtest worksheet: every other arc ASSUMES you are
     * already a resident, but residency is the prerequisite for every right in
     * the constitution — voting, standing for office, sealing testimony, jury
     * service. It is the first thing a new player hits and there was no arc for
     * it, so the worksheet's opening section had to be written from scratch.
     *
     * The verified counterparts are ACH-CIV-001..005 in AchievementCatalog:
     * this arc EXPLAINS the path, those record that you actually walked it.
     */
    'become-a-resident' => [
        'title'  => 'Becoming a resident',
        // Step 5 added at @lane-06's suggestion (their worksheet section A-4), and
        // they were right that it was the one worth not losing: you declare ONE
        // place and discover you are represented in four, from your castello to
        // the planet, without doing anything else. That is the moment nested
        // jurisdiction stops being an abstraction. Its verified counterpart is
        // ACH-CIV-006, which was already in the catalogue but not in the arc.
        'steps'  => ['Register', 'Declare where you live', 'Presence confirms', 'Residency confirmed', 'You appear at every level', 'Rights switch on'],
        'status' => 'live',
        'cls'    => 'people',
    ],

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
        // LIVE 2026-07-26. Held while the economy was read-only — the pages
        // rendered but a player could not act, so every step was a dead end.
        // F-IND-022/023/024 (lane 13, 72fdd95) put a constitutional door on the
        // built services, so the steps are walkable and the arc is honest.
        'status' => 'live',
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
        // LIVE 2026-07-26. This was the 0-of-4 arc — a player could SEE
        // assistance requests on the market page and post, answer or resolve
        // none of them. F-IND-022/023/024 closed it.
        'status' => 'live',
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
        // LIVE 2026-07-26. Held even at 3-of-4 walkable, because the ONE
        // unreachable step was the only step that was an ACTION — the other
        // three are things you look at. The alternative was deleting the tax
        // step to make it flippable, which would have taught "government pays
        // you": half the lesson, and the wrong half. The arc stayed right and
        // waited for the app. F-IND-022/023/024 made it whole.
        'status' => 'live',
        'cls'    => 'gov-orgs-people',
    ],

    'two-governments' => [
        'title'  => 'Two governments meet, trade, and merge',
        'steps'  => ['Discover a peer', 'Trust each other’s records', 'Trade talks', 'Union or border'],
        'status' => 'live',
        'cls'    => 'gov-gov',
    ],

];
