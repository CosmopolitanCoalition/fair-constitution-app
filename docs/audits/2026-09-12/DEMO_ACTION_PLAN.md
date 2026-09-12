# World of Statecraft — demo action plan

12 September 2026. Working action table for the operator-approved plan. Status describes verified implementation, not a delivery-date estimate. Update this table as each action passes its completion checks.

## Phase order

| Phase | Purpose | Status | Exit check |
|---|---|---|---|
| 0. Consolidate | Give each task a clear home; preserve place and institution context. | Completed for the identified overlapping families | Shared arrival, learning, bill, organization, legislature, host and agreement paths are implemented and checked. |
| 1. Finish browsing | Reach eligible records without loading a world-sized list. | In progress | Remaining directories/selectors offer bounded pages or search; selected records and draft work survive navigation. |
| 2A. Institutional rooms | Make civic meetings usable and recognizable. | Core rooms, floor controls, archives and role previews implemented; conference rehearsal remains | Institution-specific calls, floor positions, names and reconnect behavior pass independent-participant checks. |
| 2B. Economic actions | Give participants useful work, trade and help activities. | Paused while rooms are prioritized | Missing actions work through their existing authority and consent rules, with readable results and recovery. |
| 3. Civic scenario rehearsal | Prove complete attendee journeys through the settled paths. | After required Phase 2 actions | Independent actors complete and repeat each scenario, including refusals and recovery. |
| 4. Language, teaching and accessibility | Cover the settled flows across languages and modalities. | After scenario fixes | Translations, lessons, keyboard/screen-reader use, narrow displays and media alternatives pass the coverage review. |
| 5. Conference dress rehearsal | Verify the final demo presentation. | Last release check | The chosen demo sequence runs on the intended host and participant devices, with a known recovery route. |

Performance fixes accompany the affected screen throughout these phases. Basic accessibility is part of each implementation; the comprehensive language/accessibility review stays after feature work and scenario rehearsal, as requested. Legislative maps and the full place tree remain prominent throughout.

## Action board

| ID | Phase | Concrete action | Current state | Complete when |
|---|---|---|---|---|
| B1 | 1 | Page the market, work and public help lists; load only the selected tab. | Completed and tested | More than two pages can be traversed in both directions without duplicate/skipped records; private help stays excluded; first/late page plans use indexes. |
| B2 | 1 | Replace the first-50 agreement-party picker with indexed name search. | Completed and tested | A person beyond the former cap is findable; selected parties and agreement text survive searches/pages; no name search reads the whole people table. |
| B3 | 1 | Finish remaining asset selectors and long record lists. | Asset selectors completed and tested; histories remain | Older eligible assets/history are reachable; empty and loading states are truthful; private data remains scoped to its owner or institution. |
| P1 | Alongside affected phases | Replace world-wide term-sync reads and expensive monetary-report calculations. | Completed for Term schedules and Units & money | Institutional records page within scope; large aggregates resume in bounded work units; reports display freshness/progress without recalculating the world during page entry. |
| P2 | 1 | Finish treasury and long-history reads. | Next browsing pass | Treasury entry avoids world supply aggregation; wallet payments/stipends, seller pending orders and institution histories are paged with indexed scope and usable return paths. |
| R1 | 2A | Connect chamber, court and board live rooms. | Implemented and tested: directory, calls/discussion, floor controls, witness positioning and connected seating beyond the preview | Actual institution rosters, presider, current speaker/witness and media participants occupy the appropriate positions; exact room-to-institution access is enforced. |
| R2 | 2A | Rehearse calls with independent participants. | Local two-identity audio/video and rejoin passed; separate devices remain | Separate accounts/devices verify microphone/camera prompts, display names, floor changes, audio continuity, disconnect/rejoin and denied access to unrelated private rooms. |
| R3 | 2A | Extend role exploration and add session archives. | Completed: public Speaker preview, labeled room guides, direct institution-room links and paginated exact-session records | Visitors can inspect role responsibilities and available actions without receiving authority; older sessions open directly with agenda, votes and records. |
| E1 | 2B | Add employer posting, application review and decisions. | Applying works; employer services lack the complete UI/action path | Authorized employers manage postings and privately review applications; the applicant account is verified; worker consent and organization countersign finish the existing contract chain. |
| E2 | 2B | Add usable requests for help and participation. | Schema/public list exist; action workflow is missing | Requesters and responders can create, respond, withdraw and resolve requests under explicit ownership/visibility checks; private requests are protected. |
| E3 | 2B | Expose share issuance; audit organization board powers. | Issuance handler exists without its entry path; CGC administration requires audit | Eligible agents issue through the existing handler; recipient/units are validated; CGC appointments, shareholder elections, worker elections and chair selection use the appropriate authority. |
| S1 | 3 | Rehearse places/maps, elections, institutions and economic participation. | Pending | A visitor chooses any home place, reaches its maps, explores roles, joins an institution and completes economic activity; records and return paths are correct. Test allowed actions, refusals and recovery with independent actors. |
| L1 | 4 | Translate settled strings and connect educational content to working actions. | Partial coverage | Missing/fallback strings are reviewed; lessons describe the implemented process; priority conference languages have complete scenario coverage. |
| L2 | 4 | Complete accessibility and modality checks. | Basic controls implemented; full audit pending | Keyboard, screen reader, narrow screens, contrast, media alternatives and language/audio fallbacks pass the rehearsed flows. |
| D1 | 5 | Rehearse the demo on its intended host/devices. | Pending | Final paths, loading feedback, room connections and recovery work together. Record which checks were actually performed and any remaining limitation. |

## Verified dependencies and boundaries

- The organization directory, share offers and both agreement families already have cursor pagination. [Directories and workspaces](DIRECTORIES_AND_WORKSPACES.md) records their implementation and tests.
- `PublicVoiceRoomAccess` now recognizes public commons, committee, chamber and court rooms with exact place checks. Board rooms use current seated-member checks. See [live room evidence and remaining checks](LIVE_ROOMS.md).
- `LaborBoardService::accept` accepts a supplied user. An employer-facing endpoint must verify the application/account binding and preserve the worker's separate consent; it must not simply supply the employer as that user.
- `BoardElectionController` currently uses the agent check for generic administration flags, including CGCs. Review the controller and engine together before declaring those actions ready.
- Session archives and reporting costs are grounded in `SessionController`, `TermSyncController` and `CurrencyTelemetryService`, not in historical planning claims.
- Simulation start, stop, timing changes and reversion remain operator-controlled. No destructive reset is part of this plan. Schema updates remain additive; testing uses targeted isolated fixtures and bounded live reads.

## Directory foundation

| Item | Delivered | Evidence |
|---|---|---|
| B1: Market browsing | 25 entries per page in the selected tab only; next/previous links retain that tab; no misleading totals on unloaded tabs. Work application counts are batched for the visible postings. Null timestamps remain pageable; malformed dates/cursors fail before queries. | Fixtures traverse 120 records in every section both ways and verify privacy/exclusions and constant query counts. PostgreSQL plans use all three browse indexes, including a direct late-page seek. Live browser verified the selected Work view. |
| B2: People search | 20 consent-name matches per page; no people query until a prefix is entered. Selected parties and draft text survive searching, paging and return visits. Only the search text enters the search URL. | Prefix/cursor/privacy fixtures and interaction checks passed. Live browser returned 20 matches and exposed further pages. First/late search plans use the new name index. |
| Validation and rollout | Two additive migrations applied locally: `2026_09_12_170000_agreement_party_directory_index.php` and `2026_09_12_171000_market_section_indexes.php`. Pulling hosts must apply them for the indexed access paths. | Combined regression run: **24 isolated tests / 683 assertions passed**. Both Vue screens compiled individually; changed PHP passed syntax checks. No production frontend build, simulation action or world-record change was performed. |

The live simulated people roster contains repeated consent names; distinguish those choices using appropriate public profile information during selector refinement, without exposing wallet ownership or private residency. Live market sections were empty, so populated market pagination was verified with fixtures rather than a fabricated live transaction.

## Asset and reporting pass

| Item | Delivered | Evidence |
|---|---|---|
| B3: Owned items | Wallet and listing selectors show 20 owned items per page with literal name-prefix search. Listing eligibility is checked per asset; selection, draft text and composer state survive paging. Goods require an owned asset, matching the existing transaction service. | Asset and actual Inertia partial-response fixtures pass. Partial item requests omit wallet history and public market feeds. PostgreSQL initial/prefix/late-page plans use the owner/name index; the open-listing check stays a correlated indexed lookup. |
| P1: Term schedules | Exact-place and optional legislature views replace worldwide aggregates. Each record section has 25-row pages. Actual recorded terms, configured appointment lengths and armed election dates remain distinct. Place/map tools include a direct Term schedules link. | Scoped/privacy/cursor/settings fixtures pass. Live Anne Arundel view, legislature selection and next-page navigation work; the selected legislature retains chamber and legislative-map links. Term/refusal plans use the new place indexes. |
| P1: Money reports | Units & money reads one saved report. Explicit collection runs in host-derived queue chunks, retaining the previous publication. Totals, balance buckets and checkpoint advance atomically; revision guards discard stale deliveries/failures. The scheduler recovers lost continuations, and failed collections can resume. | Live queue collection processed **3,885,557 source records in 161 seconds**, finishing at 20:46:53 UTC. Published figures and collection dates render in the browser. Fixtures cover decimal totals, tied balances, frozen transfer dates, rollback, failures/resume, stale jobs and absence of source scans on GET. |
| Validation | **42 isolated tests / 2,010 assertions passed** across reports, term schedules, assets, market and people search. Changed PHP passed syntax checks; affected Vue components compiled individually. | Additive migrations `180000_currency_reports`, `181000_owned_asset_directory_indexes` and `182000_term_sync_directory_indexes` applied locally. Report/asset/term indexes are valid. Routes/configuration refreshed and existing Horizon workers restarted for the new job. No frontend production build or simulation control was used. |

Remaining boundaries:

- B3/P2 still cover wallet payment and stipend histories (currently 50/12), seller pending orders and other long institution records. Treasury still has legacy aggregate paths; completing the money-report reader does not make those paths bounded automatically.
- The report describes balances observed across its collection interval, not a simultaneous accounting snapshot. Its fixed ID ceiling bounds each source pass; concurrent inserts below that ceiling and balance changes can affect observations. Supply/transfers apply frozen collection dates. Transfer history currently advances through currency/ID chunks; a date/ID optimization remains worthwhile as historical volume grows.
- No owner identities or locations enter money reports. Intermediate balance buckets are removed in bounded cleanup chunks before publication. Live report checks used existing records and wrote only report/cache state.
- Actual multi-participant room calls, economic action scenarios and the final language/accessibility review remain in their phases above.
- An additional existing `TermLockstepTest` source-whitelist check fails on `ends_on` serializers in `SimBoardService.php` and `JudicialSeatService.php`. Those files were unchanged; the new scoped term suite passes. This separate failure is recorded, not treated as a passing check.

Pulling hosts must apply the three additive migrations, refresh cached routes/configuration and restart Horizon before exercising report collection. Existing worlds are retained; no reset is required.

## Room priority update

The operator prioritized LiveKit/Matrix rooms to conserve remaining usage. Economic action work and unrelated browsing are paused. Room delivery, local transport evidence, deployment steps and remaining physical-device checks are recorded in [LIVE_ROOMS.md](LIVE_ROOMS.md).

The follow-up completes R3 and repairs committee continuity: room links retain the selected hearing in its official workspace; historical hearings expose their own testimony and disable formal actions; informal discussion continues updating after adjournment. Room guides explicitly label example seats and link to actual rooms. Public Speaker previews do not load member-only office records or grant powers. Session archives page the selected legislature and its exact session's attendance, motions, public records and individual vote lists, with no current-session ballot/action controls mixed into older records.

The next main-plan development work is B3/P2 (remaining treasury and long-history browsing), then E1–E3 (economic actions). Full physical-device/conference-host rehearsal remains R2/D1. Committee testimony still shows its latest 50 entries; its full history pagination belongs to B3 alongside other long institutional records.
