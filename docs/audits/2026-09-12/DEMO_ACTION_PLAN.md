# Demo punch list

Updated 13 September 2026. **Only unfinished development and internal testing appear here.** Work proceeds in the phase order below; performance repairs accompany the affected feature.

**Done means development and the required internal tests for that scope have passed.** Move a finished scope out of this list immediately. For a partly finished item, retain only the unfinished work. Human-only checks do not keep development open. All multi-user internal tests use simulated participants.

Reference only: [completed work and evidence](DEMO_COMPLETED_WORK.md) · [deferred human/device and deployment checks](DEMO_DEFERRED_CHECKS.md) · [operator/deployment handoff](NEXT_SESSION_HANDOFF.md).

## 1. Finish remaining development

| ID | Remaining action | Internal completion check |
|---|---|---|
| E4 | Add organization board-chair ballots and retry controls. | The authenticated current seat can submit a ranking on its own board. Wrong-board, removed-seat, duplicate and closed-ballot attempts refuse. Chair selection and retry complete under the existing board rules. |
| E5 | Add CGC governor nomination and consent against the CGC's own board. | The actual overseeing executive and creating legislature complete nomination, consent and seating. Rejection and cross-institution attempts refuse; department appointments still work. |
| B3 | Page committee testimony beyond the latest 50 and remaining long civic institution histories. | Older records are reachable in both directions, remain scoped to the selected institution/hearing, and retain usable return paths. |
| P2 | Finish wallet transaction history, organization ledger/tax/conversion histories and treasury readers. | Histories page within authorized scope. Treasury entry avoids world-wide aggregation and full account/revenue/levy loads; private financial records remain private. |
| B4 | Distinguish people with the same public name in selectors. | Appropriate public profile context identifies the selected person across search pages, without exposing private residency or wallet ownership. |
| S2 | Finish interjurisdictional proposal/consent/completion controls and page lifecycle histories beyond 25. | Union, disintermediation, border settlement and restoration have reachable actions using their actual services and authority checks, with refusal and recovery fixtures. |
| Q1 | Resolve the previously recorded TermLockstepTest failure involving term-end serializers. | Check SimBoardService and JudicialSeatService against current term rules; correct the defect or obsolete test restriction and pass the targeted check without weakening constitutional requirements. |

Board details: [organization board audit](ORGANIZATION_BOARD_AUDIT.md). Civic and setup details: [setup/scenario audit](SETUP_AND_SCENARIO_AUDIT.md).

## 2. Complete internal scenario testing

| ID | Remaining action | Internal completion check |
|---|---|---|
| R2 | Run complete institutional room journeys with separate simulated participants and generated media. | Actual fixture presiders/members exercise recognition, witness positioning, names, audio continuity, disconnect/rejoin and the private-board journey. Unrelated identities cannot enter private rooms. No human participants are required. |
| S1 | Run complete civic and economic journeys through the application. | Separate simulated actors navigate places/maps and complete elections, legislature business, court proceedings, executive actions, interjurisdictional decisions, hiring, help and share issuance. Exercise real form/engine/audit boundaries, outcomes, refusals and recovery; do not substitute mocked engine success for a finished journey. |
| S3 | Check ownership changes with separate PostgreSQL connections and representative large-organization fixtures. | Concurrent issuance, resale and dissolution preserve consistent stakes and membership or roll back cleanly. Measure the synchronous percentage recalculation's latency and memory; repair demonstrated failures or overload. |

Use isolated databases, queues and test rooms. Reuse Step 5/Dev scenario sequences inside those fixtures; do not rerun or reset the existing simulated world. Record actual passes, failures and skips. Reopen a finished feature only when these checks expose a defect.

## 3. Finish language, teaching and accessibility coverage

| ID | Remaining action | Internal completion check |
|---|---|---|
| L1 | Translate settled strings and connect lessons to the working actions. | Review missing/fallback strings and lesson accuracy. Complete the selected conference-language scenario coverage after feature and scenario fixes. |
| L2 | Complete accessibility and modality checks across the settled flows. | Keyboard, screen-reader semantics, narrow layouts, contrast, media alternatives and language/audio fallbacks pass internal checks; fix the failures. |

## 4. Validate and improve setup/world generation

| ID | Remaining action | Internal completion check |
|---|---|---|
| G1 | Implement honest world-readiness verification and Step 5 completion checks. | Bounded checks record required artifacts and unresolved scopes. Validate demo and player-ready beta outcomes separately, including usable representatives, ownership structures, board chairs and scenario prerequisites. Step 5 cannot silently claim an unverified world is ready. |
| G2 | Repair simulation resume enumeration. | Stable cursor/scanned-row progress reaches later missing cohorts even when the first page already exists. Repeated resumes neither skip work nor duplicate it. |
| G3 | Reduce measured geodata, map, provisioning and simulation costs; bound progress polling and board backstops. | Replace expensive global preparation/polling with resumable bounded work and saved progress. Check cold start, resume and reuse with host-derived capacity. Make certification-backstop failures visible and review its 48-hour delay against demo timing. Preserve apportionment and ordinary-world rules. |

## 5. Final internal release check

| ID | Remaining action | Internal completion check |
|---|---|---|
| D1 | Run the complete demo presentation with simulated participants after all preceding fixes. | Final navigation, maps, civic/economic actions, room connections, loading feedback and recovery work together on the intended demo setup. Record the results and fix failures. |

Human attendance, physical-device testing and access to the remote conference box are tracked separately. They are not blockers for completing this development punch list.
