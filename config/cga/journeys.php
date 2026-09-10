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
        'steps'  => [
            [
                'label' => 'Register',
                'what'  => 'A new person creates an account on the platform.',
                'you'   => 'Fill in your name and email to create your account.',
                'href'  => '/register',
                'form'  => 'F-IND-001',
            ],
            [
                'label' => 'Declare where you live',
                'what'  => 'You name the specific place where you live.',
                'you'   => 'Search for your home jurisdiction and select it.',
                'href'  => '/civic/residency',
                'form'  => 'F-IND-003',
            ],
            [
                'label' => 'Presence confirms',
                'what'  => 'Your device sends a location ping to confirm you are physically present where you declared.',
                'you'   => 'Allow the app to read your location once.',
                'href'  => '/civic/residency',
                'form'  => 'F-IND-005',
            ],
            [
                'label' => 'Residency confirmed',
                'what'  => 'A designated officer reviews your residency claim and confirms it.',
                'you'   => 'Watch your residency status change from pending to confirmed.',
                'href'  => '/civic/residency',
                'form'  => 'F-IND-006',
            ],
            [
                'label' => 'You appear at every level',
                'what'  => 'The system links you to every tier of government above your declared place.',
                'you'   => 'Open your record to see all the chambers and governments you now belong to.',
                'href'  => '/civic/record',
                'form'  => null,
            ],
            [
                'label' => 'Rights switch on',
                'what'  => 'Your right to vote and to stand for office activates in every jurisdiction you belong to.',
                'you'   => 'Open your record to see your active rights.',
                'href'  => '/civic/record',
                'form'  => null,
            ],
        ],
        'status' => 'live',
        'cls'    => 'people',
    ],

    'election' => [
        'title'  => 'An election, end to end',
        'steps'  => [
            [
                'label' => 'Approval',
                'what'  => 'Residents see the full candidate list and mark the ones they approve of.',
                'you'   => 'Open the ballot and mark every candidate you approve of.',
                'href'  => '/elections/open-ballot',
                'form'  => null,
            ],
            [
                'label' => 'Candidate forum',
                'what'  => 'Candidates present their platforms so residents can learn about them before the ranked vote.',
                'you'   => 'Read candidate profiles and public statements before deciding your ranking.',
                'href'  => '/elections',
                'form'  => null,
            ],
            [
                'label' => 'Finalist cutoff',
                'what'  => 'The election board closes the approval window and confirms which candidates advance.',
                'you'   => 'Watch the finalist list appear on the election page.',
                'href'  => '/elections/board',
                'form'  => 'F-ELB-002',
            ],
            [
                'label' => 'Ranked vote',
                'what'  => 'Residents rank the finalists from most to least preferred.',
                'you'   => 'Open the ranked ballot and order the candidates as you prefer them.',
                'href'  => '/elections/ranked-ballot',
                'form'  => 'F-IND-007',
            ],
            [
                'label' => 'Count',
                'what'  => 'The system counts all ballots using the single transferable vote method.',
                'you'   => 'Watch the round-by-round count on the results page.',
                'href'  => '/elections/results',
                'form'  => null,
            ],
            [
                'label' => 'Seated',
                'what'  => 'The election board certifies the count and the winners are formally seated.',
                'you'   => 'Watch the results page confirm the certified winners.',
                'href'  => '/elections/board',
                'form'  => 'F-ELB-004',
            ],
            [
                'label' => 'First session',
                'what'  => 'The new chamber meets for the first time and each member takes the oath of office.',
                'you'   => 'Watch each member accept their seat and take the oath.',
                'href'  => '/legislature',
                'form'  => 'F-LEG-001',
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'committee-session' => [
        'title'  => 'A committee session, live',
        'steps'  => [
            [
                'label' => 'Convene',
                'what'  => 'The committee chair calls the meeting to order.',
                'you'   => 'Wait for the chair to open the meeting.',
                'href'  => '/legislature/committees',
                'form'  => 'F-CHR-001',
            ],
            [
                'label' => 'Quorum',
                'what'  => 'Members register their attendance and the system checks that a working majority is present.',
                'you'   => 'Register your attendance so the quorum count includes you.',
                'href'  => '/legislature/committees',
                'form'  => 'F-LEG-002',
            ],
            [
                'label' => 'Agenda',
                'what'  => 'The chair publishes the list of items the committee will address.',
                'you'   => 'Review the agenda to see what the committee will consider today.',
                'href'  => '/legislature/committees',
                'form'  => 'F-CHR-002',
            ],
            [
                'label' => 'Testimony',
                'what'  => 'Residents and invited witnesses speak on the agenda items before the committee votes.',
                'you'   => 'File testimony if you want your position on the record.',
                'href'  => '/legislature/committees',
                'form'  => 'F-SOC-002',
            ],
            [
                'label' => 'Motion',
                'what'  => 'A committee member formally proposes an action for the committee to vote on.',
                'you'   => 'Submit your motion if you have one to put before the committee.',
                'href'  => '/legislature/committees',
                'form'  => 'F-LEG-007',
            ],
            [
                'label' => 'Committee vote',
                'what'  => 'Each committee member casts a vote on the motion before them.',
                'you'   => 'Cast your vote on the motion.',
                'href'  => '/legislature/committees',
                'form'  => 'F-LEG-005',
            ],
            [
                'label' => 'Report',
                'what'  => 'The chair files a formal report recording the committee\'s decisions and recommendations.',
                'you'   => 'Read the committee report once the chair files it.',
                'href'  => '/legislature/committees',
                'form'  => 'F-CHR-004',
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'bill' => [
        'title'  => 'A bill becomes law',
        'steps'  => [
            [
                'label' => 'Introduced',
                'what'  => 'A chamber member drafts and submits a bill for the legislature to consider.',
                'you'   => 'Draft and submit your bill to start the legislative process.',
                'href'  => '/legislature/bills',
                'form'  => 'F-LEG-003',
            ],
            [
                'label' => 'Committee',
                'what'  => 'The relevant committee studies the bill, hears testimony, and decides whether to send it to the floor.',
                'you'   => 'Follow the committee\'s work and file testimony if you want your position heard.',
                'href'  => '/legislature/committees',
                'form'  => 'F-CHR-003',
            ],
            [
                'label' => 'Floor reading',
                'what'  => 'The bill\'s full text is read to all members before the vote.',
                'you'   => 'Review the bill text as it is presented to the chamber.',
                'href'  => '/legislature/bills',
                'form'  => null,
            ],
            [
                'label' => 'Floor vote',
                'what'  => 'Every member of the chamber casts a vote on whether to pass the bill.',
                'you'   => 'Cast your vote on the bill.',
                'href'  => '/legislature/bills',
                'form'  => 'F-LEG-004',
            ],
            [
                'label' => 'Enacted',
                'what'  => 'The bill reaches the required majority and is recorded as a new law.',
                'you'   => 'Review the enacted law as it appears in the chamber\'s record.',
                'href'  => '/legislature/bills',
                'form'  => null,
            ],
            [
                'label' => 'Published',
                'what'  => 'The law is added to the public record and becomes visible to everyone.',
                'you'   => 'Read the law in the public records.',
                'href'  => '/system/public-records',
                'form'  => null,
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'court-case' => [
        'title'  => 'A court case, end to end',
        'steps'  => [
            [
                'label' => 'Filed',
                'what'  => 'A resident or advocate submits a civil or criminal case to the court.',
                'you'   => 'File your case from the public docket page.',
                'href'  => '/judiciary/docket',
                'form'  => 'F-IND-017',
            ],
            [
                'label' => 'Panel',
                'what'  => 'The court accepts the case and assigns a panel of judges to hear it.',
                'you'   => 'Watch the court record the panel assignment on the judiciary page.',
                'href'  => '/judiciary',
                'form'  => 'F-JDG-001',
            ],
            [
                'label' => 'Hearings',
                'what'  => 'The panel holds scheduled hearings where the parties and witnesses appear.',
                'you'   => 'Follow the case on the public docket as each hearing is recorded.',
                'href'  => '/judiciary/docket',
                'form'  => null,
            ],
            [
                'label' => 'Evidence',
                'what'  => 'Advocates submit documentary and testimonial evidence to the panel.',
                'you'   => 'Submit your evidence through the advocate console.',
                'href'  => '/judiciary/advocate',
                'form'  => 'F-ADV-003',
            ],
            [
                'label' => 'Jury',
                'what'  => 'The panel issues a jury selection order and citizens are empanelled.',
                'you'   => 'Watch the panel post the jury selection order on the judiciary page.',
                'href'  => '/judiciary',
                'form'  => 'F-JDG-002',
            ],
            [
                'label' => 'Arguments',
                'what'  => 'Advocates file closing briefs and present final arguments before the panel.',
                'you'   => 'File your closing brief through the advocate console.',
                'href'  => '/judiciary/advocate',
                'form'  => 'F-ADV-004',
            ],
            [
                'label' => 'Deliberation',
                'what'  => 'The panel and jury deliberate in private to reach a verdict.',
                'you'   => 'Wait for the deliberation to conclude before checking the docket.',
                'href'  => '/judiciary/docket',
                'form'  => null,
            ],
            [
                'label' => 'Judgment',
                'what'  => 'The panel announces its verdict and, where applicable, issues a sentencing order.',
                'you'   => 'Read the judgment on the public case docket.',
                'href'  => '/judiciary/docket',
                'form'  => 'F-JDG-009',
            ],
            [
                'label' => 'Opinion',
                'what'  => 'The panel files a written opinion explaining the legal reasoning behind its ruling.',
                'you'   => 'Read the published opinion on the public case docket.',
                'href'  => '/judiciary/docket',
                'form'  => 'F-JDG-003',
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'budget' => [
        'title'  => 'Enacting a budget',
        'steps'  => [
            [
                'label' => 'Revenue',
                'what'  => 'The legislature reviews the jurisdiction\'s projected income for the period.',
                'you'   => 'Read the current treasury summary on the public finance page.',
                'href'  => '/economy/treasury',
                'form'  => null,
            ],
            [
                'label' => 'Budget bill',
                'what'  => 'A member introduces a bill that proposes how to spend the available funds.',
                'you'   => 'Read the introduced budget bill on the bills page.',
                'href'  => '/legislature/bills',
                'form'  => 'F-LEG-003',
            ],
            [
                'label' => 'Appropriations',
                'what'  => 'The chamber votes to approve specific spending amounts for each program.',
                'you'   => 'Watch the floor vote on the appropriations bill.',
                'href'  => '/legislature/bills',
                'form'  => 'F-LEG-004',
            ],
            [
                'label' => 'Disbursement',
                'what'  => 'Approved funds transfer from the public treasury to authorized programs.',
                'you'   => 'Track outgoing disbursements on the public finance page.',
                'href'  => '/economy/treasury',
                'form'  => 'F-IND-023',
            ],
            [
                'label' => 'Ledger',
                'what'  => 'Every transaction is recorded on the public ledger and available for inspection.',
                'you'   => 'Read the audit record on the public records page.',
                'href'  => '/system/public-records',
                'form'  => null,
            ],
        ],
        // LIVE 2026-07-26. Held while the economy was read-only — the pages
        // rendered but a player could not act, so every step was a dead end.
        // F-IND-022/023/024 (lane 13, 72fdd95) put a constitutional door on the
        // built services, so the steps are walkable and the arc is honest.
        'status' => 'live',
        'cls'    => 'gov-itself',
    ],

    'start-org' => [
        'title'  => 'Starting an organization',
        'steps'  => [
            [
                'label' => 'Register',
                'what'  => 'A resident registers a new organization with the government.',
                'you'   => 'Register your organization from the organizations registry.',
                'href'  => '/organizations',
                'form'  => 'F-IND-012',
            ],
            [
                'label' => 'Charter',
                'what'  => 'The founding members set the organization\'s name, purpose, and governance rules.',
                'you'   => 'Complete the charter details on the organization\'s profile page.',
                'href'  => '/organizations',
                'form'  => 'F-ORG-001',
            ],
            [
                'label' => 'First board',
                'what'  => 'The founding members elect the first board of directors.',
                'you'   => 'Administer the first board election from the organization\'s page.',
                'href'  => '/organizations',
                'form'  => 'F-ORG-003',
            ],
            [
                'label' => 'Onboard',
                'what'  => 'The first members apply to join the newly formed organization.',
                'you'   => 'Apply for membership from the organization\'s listing page.',
                'href'  => '/organizations',
                'form'  => 'F-IND-013',
            ],
            [
                'label' => 'Market (opt.)',
                'what'  => 'The organization optionally registers to buy and sell on the open market.',
                'you'   => 'File the market participation form from the organizations page.',
                'href'  => '/economy/market',
                'form'  => 'F-ORG-008',
            ],
        ],
        'status' => 'live',
        'cls'    => 'orgs-people',
    ],

    'board-meeting' => [
        'title'  => 'Holding a board meeting',
        'steps'  => [
            [
                'label' => 'Convene',
                'what'  => 'The board chair calls a meeting and notifies all board members.',
                'you'   => 'Notify board members and set the meeting date from the organization\'s page.',
                'href'  => '/organizations',
                'form'  => null,
            ],
            [
                'label' => 'Composition',
                'what'  => 'The current board roster is confirmed before the meeting opens.',
                'you'   => 'Verify the board member list on the organization\'s page.',
                'href'  => '/organizations',
                'form'  => null,
            ],
            [
                'label' => 'Motions',
                'what'  => 'Board members submit motions to add items to the meeting agenda.',
                'you'   => 'Submit your motion from the organization\'s governance page.',
                'href'  => '/organizations',
                'form'  => null,
            ],
            [
                'label' => 'Board vote',
                'what'  => 'The board votes on each motion in turn until the agenda is complete.',
                'you'   => 'Cast your vote on each open motion from the organization\'s page.',
                'href'  => '/organizations',
                'form'  => null,
            ],
            [
                'label' => 'Minutes',
                'what'  => 'The meeting minutes are published to the organization\'s record.',
                'you'   => 'Read the published minutes on the organization\'s page.',
                'href'  => '/organizations',
                'form'  => null,
            ],
        ],
        'status' => 'live',
        'cls'    => 'orgs-people',
    ],

    'form-a-group' => [
        'title'  => 'An informal group forms and meets',
        'steps'  => [
            [
                'label' => 'Create',
                'what'  => 'A person registers an informal group in the organization registry.',
                'you'   => 'Open the organizations page and register the group.',
                'href'  => '/organizations',
                'form'  => 'F-ORG-001',
            ],
            [
                'label' => 'Discuss',
                'what'  => 'Group members post messages on the public square.',
                'you'   => 'Post your thoughts and read what the others wrote.',
                'href'  => '/civic/square',
                'form'  => 'F-SOC-001',
            ],
            [
                'label' => 'Call a meeting',
                'what'  => 'A member sets a time and invites the rest of the group to meet.',
                'you'   => 'Send the invitation in the group\'s private room.',
                'href'  => '/civic/rooms',
                'form'  => null,
            ],
            [
                'label' => 'Decide',
                'what'  => 'The group reaches an agreement and someone records it.',
                'you'   => 'Post the decision so every member can see it.',
                'href'  => '/civic/square',
                'form'  => 'F-SOC-001',
            ],
            [
                'label' => 'Next steps (opt.)',
                'what'  => 'The group may convert to a formal organization or start a petition.',
                'you'   => 'Choose the next action in the organization registry or on the petitions page.',
                'href'  => '/organizations',
                'form'  => null,
            ],
        ],
        'status' => 'live',
        'cls'    => 'people',
    ],

    'mutual-aid' => [
        'title'  => 'Asking for and giving help',
        'steps'  => [
            [
                'label' => 'Post request',
                'what'  => 'A person lists a need for help on the open market.',
                'you'   => 'Open the open market and post a listing for what you need.',
                'href'  => '/economy/market',
                'form'  => 'F-IND-022',
            ],
            [
                'label' => 'A neighbor responds',
                'what'  => 'Another resident sees the request and offers help.',
                'you'   => 'Find the listing and submit an offer.',
                'href'  => '/economy/market',
                'form'  => 'F-IND-022',
            ],
            [
                'label' => 'Coordinate',
                'what'  => 'The two people agree on how the help will be provided.',
                'you'   => 'Exchange messages in a private room to arrange the details.',
                'href'  => '/civic/rooms',
                'form'  => null,
            ],
            [
                'label' => 'Resolved',
                'what'  => 'The request is settled and the listing is closed.',
                'you'   => 'Open the listing and mark the request as complete.',
                'href'  => '/economy/market',
                'form'  => 'F-IND-022',
            ],
        ],
        // LIVE 2026-07-26. This was the 0-of-4 arc — a player could SEE
        // assistance requests on the market page and post, answer or resolve
        // none of them. F-IND-022/023/024 closed it.
        'status' => 'live',
        'cls'    => 'people',
    ],

    'petition-to-referendum' => [
        'title'  => 'From a petition to a referendum',
        'steps'  => [
            [
                'label' => 'Petition',
                'what'  => 'A resident files a petition to place a question before the legislature.',
                'you'   => 'Open the petitions page and submit the petition text.',
                'href'  => '/civic/petitions',
                'form'  => 'F-IND-009',
            ],
            [
                'label' => 'Signatures',
                'what'  => 'Residents sign the petition until it reaches the required threshold.',
                'you'   => 'Find the petition and add your signature.',
                'href'  => '/civic/petitions',
                'form'  => 'F-IND-010',
            ],
            [
                'label' => 'Reaches legislature',
                'what'  => 'The election board certifies the signature count and sends the petition to the legislature.',
                'you'   => 'Watch the petition status on the petitions page for the certified notice.',
                'href'  => '/civic/petitions',
                'form'  => null,
            ],
            [
                'label' => 'Referendum',
                'what'  => 'The legislature votes to send the question directly to residents for a decision.',
                'you'   => 'Watch the legislature cast its delegation vote on the referendums page.',
                'href'  => '/legislature/referendums',
                'form'  => 'F-LEG-023',
            ],
            [
                'label' => 'Town hall',
                'what'  => 'Residents hold a public discussion about the question before voting.',
                'you'   => 'Post testimony or read what others have said on the public square.',
                'href'  => '/civic/square',
                'form'  => 'F-SOC-002',
            ],
            [
                'label' => 'Vote',
                'what'  => 'Eligible residents cast a yes or no vote on the referendum question.',
                'you'   => 'Open the referendum on the referendums page and cast your vote.',
                'href'  => '/legislature/referendums',
                'form'  => 'F-IND-008',
            ],
            [
                'label' => 'Result',
                'what'  => 'The count closes and the outcome is published.',
                'you'   => 'Check the result on the referendums page.',
                'href'  => '/legislature/referendums',
                'form'  => null,
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-orgs-people',
    ],

    'public-service' => [
        'title'  => 'A government creates a public service',
        'steps'  => [
            [
                'label' => 'Charter CGC',
                'what'  => 'The legislature passes an act to create a Common Good Corporation to deliver a service to everyone in the jurisdiction.',
                'you'   => 'Watch the bill move through chamber readings and become the corporation\'s founding act.',
                'href'  => '/legislature/bills',
                'form'  => 'F-LEG-019',
            ],
            [
                'label' => 'Board of Governors',
                'what'  => 'The executive nominates governors for the corporation and the legislature votes to confirm them.',
                'you'   => 'Watch the nomination arrive at the executive and the consent vote seat the board.',
                'href'  => '/executive/departments',
                'form'  => 'F-LEG-020',
            ],
            [
                'label' => 'Serves the public',
                'what'  => 'The corporation begins operating and its service is open to every resident in the jurisdiction.',
                'you'   => 'Find the organization in the registry to read its public record and see its active status.',
                'href'  => '/organizations',
                'form'  => null,
            ],
            [
                'label' => 'Monopoly path (opt.)',
                'what'  => 'The legislature votes to grant the corporation sole rights in its service area.',
                'you'   => 'Watch the monopoly acquisition vote and see the corporation\'s registry record update.',
                'href'  => '/legislature/bills',
                'form'  => 'F-LEG-026',
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-orgs-people',
    ],

    'stipend-and-tax' => [
        'title'  => 'The money between a person and their government',
        'steps'  => [
            [
                'label' => 'Stipend run',
                'what'  => 'The government clock triggers a payment of civic stipends to every eligible resident.',
                'you'   => 'Open the stipend page to see the current run, its schedule, and the amount paid per resident.',
                'href'  => '/economy/stipend',
                'form'  => null,
            ],
            [
                'label' => 'Your receipt',
                'what'  => 'The stipend amount posts as a credit in your wallet immediately after the run completes.',
                'you'   => 'Open your wallet to confirm the deposit and trace it back to the public stipend run.',
                'href'  => '/economy/wallet',
                'form'  => null,
            ],
            [
                'label' => 'Tax filing',
                'what'  => 'You send a portion of your income to the public treasury as a tax payment.',
                'you'   => 'Use the funds transfer form to send the payment to the government account.',
                'href'  => '/economy/wallet',
                'form'  => 'F-IND-023',
            ],
            [
                'label' => 'Public ledger',
                'what'  => 'Every government receipt and disbursement is recorded and open for any resident to read.',
                'you'   => 'Read the treasury page to see the jurisdiction\'s full public financial record.',
                'href'  => '/economy/treasury',
                'form'  => null,
            ],
        ],
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
        'steps'  => [
            [
                'label' => 'Discover a peer',
                'what'  => 'A jurisdiction finds another government on the federation mesh and reads its public descriptor.',
                'you'   => 'Open the federation page to see known peer jurisdictions and their connection status.',
                'href'  => '/federation',
                'form'  => null,
            ],
            [
                'label' => 'Trust each other’s records',
                'what'  => 'Both governments verify and accept each other\'s cryptographic records as authoritative.',
                'you'   => 'Watch the federation status page as the peer link becomes confirmed on both sides.',
                'href'  => '/federation',
                'form'  => null,
            ],
            [
                'label' => 'Trade talks',
                'what'  => 'Representatives from both jurisdictions negotiate and sign a formal cross-border agreement.',
                'you'   => 'Review the draft agreement on the agreements page and countersign when the terms are settled.',
                'href'  => '/economy/agreements',
                'form'  => 'F-IND-020',
            ],
            [
                'label' => 'Union or border',
                'what'  => 'The legislature votes to form a union with the peer jurisdiction or to settle their shared boundary.',
                'you'   => 'Watch the union formation vote in your chamber or read the boundary outcome in the jurisdiction record.',
                'href'  => '/jurisdictions/union-formation',
                'form'  => 'F-LEG-029',
            ],
        ],
        'status' => 'live',
        'cls'    => 'gov-gov',
    ],

];
