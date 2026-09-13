# EO-3 — Institution acts workspace

Development and internal surface tests completed on 2026-09-13. No live civic filings, seats, votes, simulation controls, database reset or production frontend build were used.

## Completed build

**Root integration completed on 13 September:** the combined 117 PHP tests / 1,731 assertions and 20 compiled Vue tests passed; the four directory indexes were applied and verified valid, routes/config refreshed and populated records read in the browser. See [integration receipt](INTEGRATION_AND_MAP_CLEANUP.md). Statements below about actions not performed by the subagent refer to its earlier handoff.

`/legislatures/{legislature}/institution-acts` is one public workspace for the six existing institution-act handlers. Legislature navigation exposes **Institutions**. Executive and judiciary pages now link to the exact source legislature's appropriate action instead of unrelated generic bill introduction. The judiciary link change was coordinated with the EO-4 owner.

| Plain-language action | Existing filing | What the workspace supplies |
|---|---|---|
| Delegate an executive committee | F-LEG-014 | Delegated powers, committee size, optional interest by the actual filing member |
| Create an elected executive | F-LEG-015 | Committee/individual structure and public charter |
| Create a department | F-LEG-016 | Function, charter, governor seats; local active executive derived server-side; no nomination prerequisite |
| Create an appointed court | F-LEG-017 | Court name and function; counts for the handler-derived constituent/committee nomination mode |
| Convert a court to elections | F-LEG-018 | Judge count and elected-court charter |
| Create a common-good corporation | F-LEG-019 | Name, public charter, goods/services and governor seats; available local oversight derived server-side |

Proposal cards show the filed act, vote snapshots and resulting institution link. Actual F-LEG-004 vote controls and the existing F-SPK-004 Speaker path are reused. The Speaker control requires the real resolvable ordinary-majority lane; no thresholds are calculated or relaxed by the page. Stored court minimums remain authoritative and are shown in the form's guidance.

Executive and court conversion processes show recorded consent totals and the selected jurisdiction's decision. The local member can open its actual constituent vote through the existing F-LEG-015/F-LEG-018 handler. A selected older process appears immediately even beyond the latest page; it has an all-processes return link. Constituent navigation honors an already-recorded exact chamber instead of silently substituting another chamber in the same place.

Public and role previews retain the six forms and public history. Filing remains authenticated and requires real current membership in the selected chamber. Requests cannot supply another jurisdiction, legislature, oversight executive, nominees, system-actor flag or delegation interest on behalf of others. Retired/foreign membership, existing institutional state and configured court size are checked through the actual engine/handlers. A CGC can still be chartered without oversight under the existing service contract; the UI explicitly explains that governor nominations need an assigned executive.

Drafts survive action switches, failed submissions and return navigation, keyed to the exact legislature. Filing/vote/paging operations show loading and recoverable error feedback. Inputs have labels, keyboard focus and plain-language actions. The dedicated Learn slot holds explanatory text. `K2_CONTENT_INSTITUTION_ACTS.md` is the authored guide source; the existing lightweight generator emitted eight English strings plus metadata and the guide registry entry. No manually added string values remain in the generated registry.

## Bounded readers and migration

Proposals, conversion histories and process constituents each use 20-row cursor pages (21-row lookahead), without total counts or offset scans. Tokens are scoped to the exact legislature, reader and selected process. Partial visits refresh only their own records and retain other navigation parameters.

Process history is a union of two disjoint scopes: processes initiated by this chamber and processes initiated elsewhere with a consent row for this jurisdiction. It does not run a correlated membership probe for every global process. Nested vote, tally, jurisdiction and chamber reads are restricted to the current page. Missing or cross-subject vote references cannot expose action controls.

`2026_09_13_120000_institution_act_directory_indexes.php` adds concurrent, rerunnable indexes for legislature/id proposal history, initiating-legislature/id process history, jurisdiction/process consent lookup and process/id consent history. It repairs only its own invalid index if interrupted and does not change civic data. **This subagent did not apply the migration or claim a PostgreSQL execution-plan measurement.** Root integration should run the additive migration through the established deployment path.

## Executed evidence

| Check | Result |
|---|---|
| `docker exec fc_app php vendor/bin/phpunit tests/Unit/InstitutionActWorkspaceTest.php tests/Unit/LegislatureWorkspaceTest.php` | **19 tests / 396 assertions passed**, 8.513 s, 22 MB |
| `docker exec fc_vite node --experimental-vm-modules --test tests/js/institutionActs.test.mjs` | **6 compiled Vue tests passed**, 1.179 s |
| `docker exec fc_vite node scripts/education/build_education_payload.mjs` | Passed; only the new guide registry and English education string/meta files changed |
| Explicit-path Pint on the new controller, reader, PHP fixture and index migration | Passed/formatted |
| `git diff --check` | Passed |

The PHP fixture asserts a named **SQLite `:memory:`** connection before schema work, uses exclusively synthetic UUIDs, fakes the bus and rejects stray HTTP. The existing navigation fixture also explicitly selects private SQLite. Audit transport, global role lookup, training and settings lookup are doubles; **ChamberActor, controller, ConstitutionalEngine, validator, all six proposal handlers, ChamberVoteService, vote counting/public records, rejection dispatch and both local-consent opening handlers are real**.

The fixture demonstrates all six valid filings reach their exact proposal/vote records, with forged extra fields excluded; all six complete real recorded rejection votes; and a tied CGC proposal resolves through the actual Speaker path. It covers foreign/retired/guest denial, configured court minimum changes, repeat consent opening refusal, selected older constituent consent, stored chamber identity, malformed vote references, scoped 43-record paging in all three directories, reversible cursors, no total/offset queries, public read/authenticated write route contracts and exclusion of a deleted locally participating process.

The compiled Vue fixture renders the actual page, shared consent card, tally and pager with synthetic Inertia callbacks. It verifies six payloads, labeled fields, independent retained drafts, busy/refusal/retry feedback, preview/state gates, existing public voting/Speaker URLs, constituent opening and cursor merging/recovery. It does not impersonate a browser acceptance run.

## Evidence boundaries

This completes the EO-3 **filing, browsing and voting surface build**. The new tests do not claim full positive adoption/seating/election completion of all six institutions: enactment is explicitly asserted never called in this fixture, and no mocked adoption success is presented as a completed journey. Existing institution adoption/appointment/election services were not changed by EO-3; their separate lifecycle checks remain separate evidence.

A forming stub with no recorded source legislature does not receive a guessed source chamber on its executive/judiciary page. The actual legislature's Institutions navigation still provides all applicable creation actions. No judicial nomination authority or new constitutional rule was invented. No master plan, completed register, commit or deployment was performed by this subagent.
