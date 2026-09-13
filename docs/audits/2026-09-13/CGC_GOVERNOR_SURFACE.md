# CGC governor nomination and consent surface

Date: 2026-09-13. Scope: E5's current-board governor workspace, bounded nominee search, legislative consent and Speaker tie resolution. Development and internal testing are complete for this surface. The backend's nomination, seating, rejection and expiry evidence is recorded separately in [CGC_GOVERNOR_WORKFLOW.md](CGC_GOVERNOR_WORKFLOW.md).

## Delivered behavior

| Area | Implemented and internally checked |
| --- | --- |
| Current institutional context | The organization's board page identifies its exact overseeing executive and creating legislature. Eligibility requires the actual current board and live CGC, creator and overseer relationships. Nomination is available only to a seated principal of that exact executive and only when a governor slot is truly empty. A principal elsewhere who is an advisor here receives a role preview. |
| Nominee selection | Search begins only on request. Name and public-handle prefixes use indexed seek pages of 20 people; an exact existing profile reference provides another selection route. Candidates must have an active association with the CGC's jurisdiction. Results show chosen civic names, public social context or the existing Resident reference; legal names and private profiles are neither searched nor exposed. Repeated names retain distinct public profile links and reference context. |
| Nomination action | The organization-scoped POST sends the selected nominee and optional dossier through the existing F-EXE-001 engine. Organization and jurisdiction IDs come from the route object. The selected person and dossier survive directory paging, remounts and failed submissions. Loading, empty results, errors and retry feedback are visible. |
| Appointment browsing | Current-board governor appointment history has independent seek pagination. It shows public nominee context, nomination dossier, status and available term dates. Search and history navigation preserve each other's parameters. Historical or superseded nominations remain readable without receiving current appointment controls. |
| Legislative consent | The shared ConsentVoteCard executes the existing `/votes/{vote}/cast` action. It is used by the CGC workspace and the existing legislative oversight consent surfaces. Only an eligible current member of the exact creating legislature can cast on the current valid open appointment consent. Existing casts, the Speaker, foreign members, stale appointments and malformed vote associations do not gain an ordinary cast action. A foreign or malformed vote is not shown as the appointment's tally. |
| Speaker resolution | A current Speaker receives yes/no tie controls only for the exact current appointment's closed, recorded, resolvable tied consent. The controls execute `/votes/{vote}/tiebreak` and retain an explanation through errors and retry. The actual backend engine confirms yes seats the nominee and no rejects the nominee and frees the slot. Duplicate or foreign-actor resolution is refused. |
| Existing board surfaces | Ownership and worker board elections and chair controls remain connected. Seated governor and chair labels now follow the same public-name contract as nominee search rather than falling back to private profile names. |

## Code ownership

- `app/Http/Controllers/Organizations/BoardElectionController.php`: lazy independent props and organization-scoped nomination action.
- `app/Support/CgcGovernorWorkspace.php`: exact institutional context, current appointment eligibility, consent presentation and bounded history.
- `app/Support/GovernorNomineeDirectory.php`: scoped public-name/reference search and cursor traversal.
- `database/migrations/2026_09_13_101000_governor_nominee_directory_indexes.php`: three concurrent partial prefix indexes.
- `resources/js/Components/Organizations/CgcGovernors.vue`: selection, nomination draft and appointment workspace.
- `resources/js/Components/Legislature/ConsentVoteCard.vue`: shared ordinary consent and Speaker tie actions.
- `resources/js/Pages/Organizations/BoardElections.vue`, `resources/js/Pages/Legislature/Oversight.vue`: workspace and shared consent integration.
- `routes/web.php`: organization governor nomination POST route.

## Passed internal checks

| Check | Result and limits |
| --- | --- |
| `CgcGovernorSurfaceTest`, `BoardElectionSurfaceTest`, `BoardChairWorkflowTest` | **37 tests / 435 assertions passed**, 16.784 s, 24 MB. Explicit private SQLite fixtures exercise the actual controller and vote presenter, scoped nominee pages, public-name privacy, retained current-board scope, malformed consent rejection, current Speaker tie affordances and existing election/chair regressions. The surface nomination engine is doubled in this fixture; real engine acceptance is covered below. |
| `tests/js/cgcGovernors.test.mjs` | **9 tests passed**. Compiled Vue components with synthetic navigation and media-free DOM rendering verify board integration, lazy and independent props, cursor preservation, repeated-name selection, retained drafts, exact payloads, loading/errors/retry, ordinary vote submissions, yes/no Speaker submissions and refusal of unavailable tie actions. The Oversight component is compiled and its shared-component wiring is checked; this does not claim a human browser walkthrough. |
| Backend-created nomination into the actual reader | Backend owner's **1 test / 12 assertions passed**: real F-EXE-001 creates an `appointment_consent` vote which the workspace returns with the exact vote URL, dossier and public nominee name. Creator members can cast; existing casts, Speaker and foreign members cannot use the ordinary action. |
| Real tied-consent engine flow | Backend owner's **3 tests / 61 assertions passed**: real F-EXE-001 nomination, two F-LEG-004 casts that close tied, current Speaker reader affordance, and F-SPK-004 yes/seated and no/rejected results. Includes a configured seven-year term, foreign-actor refusal, duplicate refusal and exactly one public decision. No live civic actions. |
| `IsolatedGovernorNomineePlanTest` | Parent executed **1 test / 61 assertions passed** in disposable PostgreSQL database `codex_governors_f41092365ea6`, 10.399 s, 16 MB. The fixture verified exact database identity and empty public schema, used a rollback transaction and verified cleanup. The parent then removed only that disposable database. |
| Actual PostgreSQL search plans | Three production query lanes used their dedicated public-prefix index and the existing active-association index on synthetic 20,000 users, 10,000 social profiles and 20,000 associations. Warm first-page execution times were chosen name **0.313 ms**, social name **0.411 ms**, and public handle **0.311 ms**. Forward/back traversal passed. These are bounded synthetic query checks, not a claim of measured planet-scale throughput. |
| Migration and route activation | Parent applied `2026_09_13_101000_governor_nominee_directory_indexes` locally in approximately 6 s and verified the three expected indexes have `indisvalid=true`; route cache was cleared. No destructive schema operation or world reset was used. |
| Patch integrity | `git diff --check` passed. No production frontend build was run. |

Reproduction commands for the main surface suites:

```text
docker exec fc_app php vendor/bin/phpunit tests/Unit/CgcGovernorSurfaceTest.php tests/Unit/BoardElectionSurfaceTest.php tests/Unit/BoardChairWorkflowTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/cgcGovernors.test.mjs
```

The optional PostgreSQL fixture requires a newly created, empty database named `codex_governors_<12 hexadecimal characters>` and `WOS_GOVERNOR_FIXTURE_DB` set to that exact name. It refuses ordinary database names and any existing public tables. It must never target the running world database.

## Remaining scope

No additional repair is known within this bounded CGC governor surface after these checks. Human presentation feedback, broad language/accessibility coverage and remote conference-host deployment verification remain separate project work; they are not prerequisites for claiming the isolated development checks above passed. This report does not claim those wider checks, a live nomination on the existing world, or a production build.
