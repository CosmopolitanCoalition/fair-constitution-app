# One public profile: public activity, publications and office history

Completed 13 September 2026 following the operator's clarification: **one person has one public profile; candidacy, public service and other public activity belong together**. The former `/candidates/{candidacy}` and `?candidate=` entry points still redirect to that person's `/people?who=...&tab=candidacy`. No new profile type, identity or route was introduced.

## Delivered

- The public activity reader now pages past its former 20-entry cutoff. Every page retains the selected person and provides next/previous/first navigation and exact audit receipt links. Raw audit payloads, rejected actions and the existing private/location exclusions remain outside this public projection.
- Published documents are also available inside the Record tab, through the existing `public_records` publication boundary. All records attributed there to the selected actor are reachable, including records outside the former audit-module list. Titles, full published text, correction references and available audit links remain together on that profile.
- Office history reads the saved holder from `terms`, including completed, vacated and removed terms. Reusable judicial/board seat rows no longer cause former holders to disappear. Legislative, executive and judicial seat records without a corresponding term are included as legacy history; linked terms and seats are not duplicated. Deleted terms/seat records are excluded consistently with their existing model visibility.
- All saved term kinds have plain-language titles, including legislative, executive, judicial, board, election-board and civil offices. Selected-page seat IDs resolve back to institutions, with the recorded jurisdiction as a fallback. A scheduled expiry is explicitly distinguished from an available actual departure date; missing historical facts are not inferred from today's seat holder.
- The Office tab is present for former office holders even when there is no current office summary. Duplicate current-office cards were removed from that tab. Existing current-office resolution remains available for the profile overview and is not used to grant new authority.
- Public office and activity remain visible regardless of private social biography, handle or achievement preferences. The existing separate privacy rules remain enforced.
- History pages are independent Inertia partial responses. They do not rebuild candidacy/standing/endorsement data, current-office summaries or unrelated record sections. Candidacy details load when that tab is opened, with loading/error feedback. The owner's existing statement initializes on arrival and survives unrelated history visits.

## Executed verification

**14 isolated PHP tests / 151 assertions passed** in 7.077 seconds, 20 MB:

```text
docker exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: fc_app php vendor/bin/phpunit tests/Unit/PersonProfileHistoryTest.php tests/Unit/LearnProfileReadFixtureTest.php tests/Unit/CandidacyPublicNameTest.php --colors=never
```

The new fixture explicitly creates and asserts its named SQLite `:memory:` connection before schema work. Actual readers, profile controller and Inertia partial response resolution execute. Coverage includes 45 actions, 43 full publications/corrections, 48 combined term/legacy office records, bidirectional traversal, exact large audit sequences, malformed/cross-person/cross-reader cursors, prior holders after replacement, duplicate exclusion, private social fields and unchanged candidate redirects. No live-PG fixture helper, queue or external transport runs. The older achievement fixture retains its actual award-ledger read while unrelated office/history readers are doubled there; the new history fixture exercises those real readers separately.

**3 compiled Vue component tests passed** for the real profile, office-history component and shared pager. They verify full text, historical dates/statuses, exact links, independent cursor merging, loading/error/first-page recovery, same-profile candidacy navigation, lazy statement initialization and draft retention. Browser requests in this component fixture are synthetic.

Actual browser inspection of Ruth Ellery's existing profile confirmed judicial and legislative terms, eight published seating/voting records, and two candidacies accessible within the same person URL. The selected live person has fewer than 20 documents; multi-page traversal and former-holder scenarios are established by the isolated fixtures, not claimed as populated live-browser coverage.

## PostgreSQL and rollout

`2026_09_13_130000_person_public_history_indexes.php` was applied locally in approximately 50 seconds. All three indexes were verified valid by a bounded catalog read. It creates concurrent, rerunnable indexes for selected-actor audit/publication seeks and selected-holder term history; invalid index recovery is limited to those named indexes. No civic records or existing histories were rewritten.

Actual PostgreSQL reads and EXPLAINs were limited to the selected public person under a four-second statement timeout. The activity query used the new partial seek index (15.76 ms); the eight-publication query chose the existing actor index (14.95 ms); the two-office union used the new holder index and existing indexed seat/institution lookups (65.72 ms). These are scoped observations, not a planet-scale throughput guarantee.

Pulling hosts apply the additive migration normally. No new routes/configuration or worker behavior changed, and no frontend production build, simulation control or live civic action was run.

The existing B6 candidacy standings/endorsement-expansion performance work remains open under the clarified **shared public profile** heading. This additional requested history repair is archived as completed without adding a punch item or claiming B6/review closure. Fixed-baseline counts remain 13 builds closed / 20 remaining and 2 full reviews closed / 27 remaining.
