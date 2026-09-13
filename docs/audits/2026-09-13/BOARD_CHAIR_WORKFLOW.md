# Board-chair participation: completed E4

13 September 2026. Development and targeted internal tests are complete. No vote or office was changed in the existing simulated world.

## Delivered

The organization board page now lets current seated members submit public ranked chair ballots through the constitutional engine. The new F-ORG-010 form is the participant door into the existing WF-ORG-05 workflow; owner/worker election administration remains separate. Existing RCV counting, full-board majority snapshots and chair-seating consequences are reused.

- Members explicitly select the seat they hold. A person holding several legitimate seats can cast each separately and see each receipt. Unrelated or removed seats cannot be selected.
- Candidate choices use public names and seat context. Stored RCV rounds resolve board-seat names rather than looking for legislature members.
- Rankings contain one or more distinct current candidates; duplicate submissions, stale/closed ballots and wrong-board attempts refuse. Submitted rankings are immutable and public.
- An unfilled chair can reopen after an unsuccessful result. Repeated opening preserves an ongoing ballot and its casts; it cannot unseat an elected chair.
- Composition changes void unfinished chair ballots, clear the old chair and open the replacement electorate. A legacy older open ballot cannot supersede a newer completed result.
- Mirror refusal, engine transactions and public-record/audit calls remain in the existing engine boundary. UI controls use natural language, keyboard-accessible ordering and per-form processing/errors.

## Internal evidence

`BoardChairWorkflowTest`, `BoardElectionSurfaceTest`, and `BoardCertificationScopeTest`: **42 tests, 290 assertions passed**. The chair test uses named private in-memory SQLite tables and simulated owner, worker and governor seats.

The workflow test exercises the actual form engine, handler, vote open/cast/RCV count, chair seating, public-record persistence and presenter/controller behavior. It covers successful election, failed result and retry, duplicate opening, wrong/removed/foreign seats, invalid rankings, duplicate/closed/superseded ballots, composition changes, legacy unfinished ballots, mirrored instances, route-scope tampering, and a person casting two held seats before the third member completes the count.

The fixture substitutes audit transport, global role lookup and settings lookup. It verifies audit calls and linked public records, not PostgreSQL chain hashing, concurrent locking or the complete real-browser multi-actor journey. Those broader checks remain in the internal review register; they are not human-only deferrals.

The changed BoardElections Vue script, template and scoped style compile individually. Changed PHP passes syntax checks. A read-only browser visit to the existing 'Eua fo'ou Community Health Corporation board page loads correctly, retains its organization/oversight navigation and shows the actual named board roster. The visitor was not a seated member, so this browser check is not a ballot-submission claim.

An independent review identified the multiple-seat issue; its repair and regression test passed. Final review found no additional concrete defects. Shared vote/board services require existing Horizon workers to restart on pulling hosts. No new database migration is needed for this feature.
