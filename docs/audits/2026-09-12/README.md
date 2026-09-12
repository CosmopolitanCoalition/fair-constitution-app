# World of Statecraft: feature inventory and demo plan

12 September 2026. E: checkout. This review precedes application changes.

## Assessment

The main problem is the relationship between pages, not simply the number of pages. The application exposes implementation modules, workflow steps, public reference material and host operations as competing destinations. Many screens do have real data and action handlers. Several important user journeys still stop before completion or lose the selected place along the way.

Prune duplicate entry points first. Put specialized tools inside their place, institution or work item. Complete the missing steps of selected demo workflows. Do not delete constitutional capabilities merely because their current screens are confusing.

## Inventory coverage

The filesystem contains **134 Vue page components**. The JavaScript registry has **11 primary entries**, **88 full-menu entries in 13 sections**, and **60 tour stops**. Laravel exports **547 routes**, including APIs and actions; these are not 547 screens. The inventory assigns a source review to every page component. Dynamic variants and resolver routes are listed with their shared component where identified. This is not an exhaustive audit of every nested control or form handler.

| Feature family | Pages | Assessment |
|---|---:|---|
| Arrival and accounts | 7 | Account and invite flows exist. Home and Launchpad duplicate entry content. |
| Civic life and community | 18 | Feeds, residency, discussion and progress exist. Context is lost in feed links; discussion destinations overlap. |
| Elections and government | 35 | Substantial election, legislative, executive and judicial workflows. Meeting and jury paths have specific incomplete controls. |
| Places and maps | 9 | Retain the developed mapping tools. Lifecycle pages vary between executable actions and explanatory reports. |
| Organizations and economy | 20 | Listings, agreements, transfers and organization actions exist. Employer decisions, help participation and share issuance lack located user entry paths. |
| Learning | 4 | Existing learning/media infrastructure. Guides duplicates journey navigation. Lesson text and content delivery need repair. |
| System records and support | 15 | Useful records, support and references mixed with developer coverage. Some reads are unbounded. |
| Hosting and development | 26 | Real setup, host controls and fixtures. These should not compete with everyday player activities. |

Source trace: [source-inventory.json](source-inventory.json), [routes.json](routes.json). Page reviews: [arrival](arrival-inventory.json), [civic and government](civic-inventory.json), [economy and learning](economy-learning-inventory.json), [system and hosting](system-inventory.json). [inventory.json](inventory.json) combines the page records.

## What to consolidate

| Current overlap | Proposed treatment | Preserve |
|---|---|---|
| Home / Launchpad | One shared arrival flow; one signed-in action home | Invite continuation and guest access |
| Journeys / Guides / Tour / Learn | One Learn home with scenarios, lessons and reference | Existing progress, quizzes and tour mode |
| Public square / halls / live square / live halls / messages | One Community directory with clear public, live and private labels | Public-record retention, private-message boundaries and meeting permissions |
| Legislature Show / Chamber / Session / Speaker tools | A selected legislature workspace with overview and session tools | Distinct powers and their authorization |
| Bill Detail / Bill Conversation | A bill workspace with text, discussion, votes and history | The complete legislative record |
| Organization registry / board / economics / co-determination | A selected organization workspace | Board elections, worker rights and economic operations |
| Market / Exchange / Agreements / Resident Agreements | Shared work-and-trade entry; agreements follow the parties and transactions | Different agreement types and ownership rules |
| Operator Home / Console / Roles / legacy Operations / Federation | One host entry with task-specific sections | Working controls currently located in older consoles |

These are proposals to consolidate navigation and presentation. They are not claims that the corresponding backend features are duplicates. The detailed page entries contain source citations for each recommendation.

## Proposed everyday navigation

**Today · Places · Community · Work & trade · Learn · My profile**

- Today shows concrete actions and upcoming events. A named item opens that exact item.
- Places provides world exploration and a selected place's government, elections, courts and records.
- Community holds public discussion, private conversation, live meetings, petitions and organizations.
- Work & trade provides jobs, listings, agreements, wallet and exchange.
- Learn contains guided scenarios, readable lessons, videos and help.
- My profile holds residency, roles, activity, achievements and preferences.

Place → institution → work item is the navigation chain. A ballot belongs to an election; a session belongs to a chamber; a case belongs to a court. Deep links and public browsing remain available. This follows the settled ruling that role permissions govern actions rather than observation. Host tools and developer tools have separate entry points.

This is the recommended structure for review, not an approved replacement already applied to the game. New operator choices must be recorded through the existing open-question rubric before implementation.

## Confirmed repair priorities

1. **Demo session cleanup can overwrite another session's later edit.** UPDATE reversal restores the earlier full row using its primary key without a later-write comparison. Source finding only; no live cleanup was triggered. See `app/Services/Demo/DemoSessionService.php:224`. Existing demo capture, void entries, expiry and instant residency are implemented; the remaining question is their complete behavior under concurrent use.
2. **Selected event context is lost.** Opening Earth on the civic feed reached Anne Arundel's election in the signed-in browser. All election feed/calendar links use `/elections`; that resolver chooses the viewer's election. See `app/Services/TodayFeedService.php:146` and `:357`.
3. **Learning is unreadable in a demonstrated path.** `/journeys/election` links to `/learn/election_board`, which displayed literal `c_education...` title/question keys. The lesson template also starts with the quiz rather than teaching content. See `resources/js/Pages/Learn/Lesson.vue:57` and `:67`.
4. **Live committee and juror paths are incomplete.** The live committee screen has real floor actions but incomplete vote/call controls. The juror's enabled deliberation entry has neither a destination nor an event handler. See `resources/js/Pages/Legislature/LiveCivicRoom.vue:175`, `:243`, and `resources/js/Pages/Judiciary/JurorView.vue:264`.
5. **Several page requests perform unbounded work.** Economy Home checks the full ledger per request; Term Sync loads all active legislatures; co-determination loads all boards before focus. Other findings cover public records and build polling. These routes were not load-tested. See `app/Http/Controllers/Economy/EconomyController.php:64`, `app/Http/Controllers/System/TermSyncController.php:50`, and `app/Http/Controllers/Organizations/CoDeterminationController.php:89`.
6. **Economic workflows need the other party's actions.** Applications exist; employer posting and decision interfaces were not located. Help requests are read-only. Issuance exists in an engine handler without a located UI invocation. Complete the chosen transaction from both parties' perspectives. See the economy review for bounded search evidence and limits.

The earlier Atlas null-snapshot error remains recorded in the initial review. It is part of the inventory, not the starting point of a redesign.

## Demo delivery gates

| Gate | Work | Evidence required to call it complete |
|---|---|---|
| 1. Reliable shared state | Check demo classification, role flow, capture/cleanup, beta residency, and expensive page reads | Two isolated test sessions edit a shared record; logout/expiry preserves later valid changes. Production/beta behavior remains distinct. No unbounded work in selected paths. |
| 2. Consistent navigation | Preserve selected place and item; reduce duplicated entrances; expose contextual tools | Every named link opens the named object. Back/next and browser history retain context. Public viewers can observe; unauthorized actions explain their requirement. |
| 3. Three completed scenarios | Election participation; a legislative meeting or bill; an economic agreement or trade | Both/all actors can complete the workflow, see the resulting record and understand the next state. Use suitable existing data or isolated fixtures, not a reset of this world. |
| 4. Teachable and accessible | Readable lesson content, understandable labels, keyboard use, narrow-screen layout and language/audio/caption fallbacks | A first-time attendee completes a scenario without operator narration. Check the actual conference language choices and low-bandwidth behavior. |
| 5. Conference rehearsal | Guest observer, new beta participant and acting official follow the selected paths | A repeatable script records entry, expected state, action, outcome and recovery. No dead CTAs or developer jargon in the rehearsed flow. |

The existing election journey is a useful starting structure, but its checkmarks are user-declared progress, not proof that civic actions occurred. Preserve that distinction; do not use completion medals as workflow validation.

The inspected Anne Arundel election showed zero candidates and missing future schedule dates. This is a readiness observation about one selected election, not a conclusion about the whole simulated world. Choose and verify the actual presentation scenarios before promising a populated ballot or live meeting.

## Verification and limits

- Signed-in browser reads covered Earth, civic home, the reached Anne Arundel election, journeys, the election journey and its lesson. No ballots, quiz answers, forms or demo actions were submitted; no logout was triggered.
- All 134 components have source-review entries. Counts and evidence-file existence were checked by the aggregation script. Source wiring does not establish that all workflows work against live data.
- The inventory map was rendered locally and its group expansion and view switching were checked. No application build or full test suite was run for this documentation-only work.
- No application code, schema, settings, simulation records or Docker services were changed. The audit files remain local and uncommitted. Source code may change as Claude continues development; this inventory is a dated snapshot.

Rebuild source inventory with `node docs/audits/2026-09-12/scan.mjs` after refreshing the route export. Rebuild the inline map with `node docs/audits/2026-09-12/build-map.mjs <absolute-output.html>`. Human review records must be updated when implementation changes.
