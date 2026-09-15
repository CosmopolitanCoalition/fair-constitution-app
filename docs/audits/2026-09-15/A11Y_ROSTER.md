# A11Y_ROSTER — the derived accessibility denominator (2026-09-15)

Operator standard: WCAG 2.1 AA on EVERY page. The denominator is DERIVED
from the authoritative route table by rule, never a hand list.

Source route table: `tests/browser/roster/route-list.json`
(`php artisan route:list --json`, GET routes only; captured 2026-09-15).
Derivation: `deriveRoster()` in `tests/browser/roster/roster.mjs`.
Pins: `tests/browser/roster/roster.test.mjs` (node --test).
Sample values: `php artisan a11y:roster-ids` on box E; resolved URLs:
`node tests/browser/roster/resolve-ids.mjs` (200 or 302-to-login = resolved; 404 = wrong id).

## Counts per class

| Class | Count | Scanned as a page |
|---|---:|---|
| guest param-free pages | 56 | yes |
| signed-in param-free pages | 42 | yes (signed-in mode) |
| parameterised pages | 62 | yes (signed-in mode, sample URL) |
| viewer-bound pages | 1 | yes (signed-in mode) |
| machine endpoints | 90 | no (not a page) |
| total GET routes | 251 | |

Parameterised pages resolved to a live sample URL: 50 of 62. NO SAMPLE (no live row): 12.

## Signed-in param-free pages (45)

Middleware is auth-class; they render for a signed-in resident.

- `/board`  (board.show)
- `/civic`  (civic.home)
- `/civic/halls`  (civic.halls)
- `/civic/identity`  (civic.identity)
- `/civic/jurisdictions/search`  (civic.jurisdictions.search)
- `/civic/petitions`  (civic.petitions.index)
- `/civic/record`  (civic.record)
- `/civic/relocation`  (civic.relocation)
- `/civic/residency`  (civic.residency)
- `/civic/rooms`  (civic.rooms.index)
- `/civic/rooms/new`  (civic.rooms.create)
- `/civic/square`  (civic.square)
- `/constitutional-challenges`  (judiciary.challenges.index)
- `/dev/electoral-kit`  (dev.electoral-kit)
- `/dev/executive-kit`  (dev.executive-kit)
- `/dev/judiciary-kit`  (dev.judiciary-kit)
- `/dev/legislature-kit`  (dev.legislature-kit)
- `/dev/playtest/state`  (dev.playtest.state)
- `/dev/scenario/state`  (dev.scenario.state)
- `/dev/users`  (dev.users)
- `/elections`  (elections.index)
- `/elections/board`  (/elections/board)
- `/elections/candidacy`  (elections.entry.candidacy)
- `/elections/countback`  (/elections/countback)
- `/elections/open-ballot`  (elections.entry.open-ballot)
- `/elections/ranked-ballot`  (elections.entry.ranked-ballot)
- `/elections/results`  (elections.entry.results)
- `/judiciary/advocate`  (judiciary.advocate)
- `/judiciary/docket`  (judiciary.docket.mine)
- `/operator`  (operator.home)
- `/operator/console`  (operator.console)
- `/operator/dns`  (operator.dns)
- `/operator/identity`  (operator.identity)
- `/operator/mesh`  (operator.mesh)
- `/operator/moderation`  (operator.moderation)
- `/operator/operations/apply-status`  (operator.operations.apply-status)
- `/operator/roles`  (operator.roles)
- `/operator/versioning`  (operator.versioning)
- `/organizations/co-determination`  (organizations.co-determination)
- `/organizations/transfers-conversions`  (organizations.transfers-conversions)
- `/support/tickets`  (support.tickets)
- `/system/amendments`  (system.amendments)
- `/system/audit-chain`  (system.audit-chain)
- `/system/translations`  (system.translations)
- `/system/translations/progress`  (system.translations.progress)

## Viewer-bound pages (1)

No auth middleware, but the controller resolves the viewer and sends a guest to login.

- `/people`  (people.show)

## Parameterised pages (62)

Each with its sample URL (curl code) or NO SAMPLE with the missing parameter.

| Route template | Params | Sample URL | Code |
|---|---|---|---|
| `/bills/{bill}` | bill | `/bills/931fd187-7a9f-4c1e-a406-1846aec2d37f` | 200 |
| `/bills/{bill}/conversation` | bill | `/bills/931fd187-7a9f-4c1e-a406-1846aec2d37f/conversation` | 200 |
| `/candidates/{candidacy}` | candidacy | `/candidates/049565b1-7ac1-43d7-adc0-87697f0be4df` | 302 |
| `/cases/{case}` | case | NO SAMPLE (missing case) | — |
| `/civic/petitions/{petition}` | petition | NO SAMPLE (missing petition) | — |
| `/civic/rooms/{space}` | space | `/civic/rooms/80774986-0953-47a5-9abb-016a4b84b059` | 302 |
| `/committees/{committee}` | committee | `/committees/01a076a0-4857-70b5-9ba3-6c205fa0a605` | 200 |
| `/constitutional-challenges/{challenge}` | challenge | NO SAMPLE (missing challenge) | — |
| `/departments/{department}` | department | `/departments/01a076a0-4ab9-726c-b45b-2a19010e2048` | 200 |
| `/departments/{department}/reporting` | department | `/departments/01a076a0-4ab9-726c-b45b-2a19010e2048/reporting` | 200 |
| `/economy/agreements/{contract}` | contract | NO SAMPLE (missing contract) | — |
| `/economy/help/{assistance}` | assistance | NO SAMPLE (missing assistance) | — |
| `/economy/market/{listing}` | listing | NO SAMPLE (missing listing) | — |
| `/economy/requests/{posting}` | posting | NO SAMPLE (missing posting) | — |
| `/elections/{election}` | election | `/elections/01a076a0-43e8-7160-a7c6-02daf9924100` | 302 |
| `/elections/{election}/candidacy` | election | `/elections/01a076a0-43e8-7160-a7c6-02daf9924100/candidacy` | 302 |
| `/elections/{election}/open-ballot` | election | `/elections/01a076a0-43e8-7160-a7c6-02daf9924100/open-ballot` | 302 |
| `/elections/{election}/ranked-ballot` | election | `/elections/01a076a0-43e8-7160-a7c6-02daf9924100/ranked-ballot` | 302 |
| `/elections/{election}/results` | election | `/elections/01a076a0-43e8-7160-a7c6-02daf9924100/results` | 302 |
| `/executive/{sub?}` | sub | `/executive` | 302 |
| `/executives/{executive}` | executive | `/executives/26a10236-3598-4a98-b419-ddcc8f71133f` | 200 |
| `/executives/{executive}/actions` | executive | `/executives/26a10236-3598-4a98-b419-ddcc8f71133f/actions` | 200 |
| `/executives/{executive}/departments` | executive | `/executives/26a10236-3598-4a98-b419-ddcc8f71133f/departments` | 200 |
| `/i/{token}` | token | NO SAMPLE (missing token) | — |
| `/journeys/{id}` | id | `/journeys/become-a-resident` | 200 |
| `/judiciaries/{judiciary}` | judiciary | `/judiciaries/974c0e37-c564-4943-9d10-0ff9c8c2f675` | 200 |
| `/judiciaries/{judiciary}/docket` | judiciary | `/judiciaries/974c0e37-c564-4943-9d10-0ff9c8c2f675/docket` | 200 |
| `/judiciary/jury/{summons}` | summons | NO SAMPLE (missing summons) | — |
| `/judiciary/{sub?}` | sub | `/judiciary` | 302 |
| `/jurisdictions/{jurisdiction}` | jurisdiction | `/jurisdictions/vat-1-holy-see` | 200 |
| `/jurisdictions/{jurisdiction}/map` | jurisdiction | `/jurisdictions/vat-1-holy-see/map` | 200 |
| `/learn/manage/{module}` | module | `/learn/manage/chamber-basics` | 200 |
| `/learn/{track}/{module?}` | track, module | `/learn/legislature` | 200 |
| `/legislature/{sub?}` | sub | `/legislature` | 302 |
| `/legislatures/{legislature_id}` | legislature_id | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984` | 302 |
| `/legislatures/{legislature_id}/districts` | legislature_id | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/districts` | 302 |
| `/legislatures/{legislature_id}/panels` | legislature_id | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/panels` | 200 |
| `/legislatures/{legislature_id}/type-b-map` | legislature_id | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/type-b-map` | 302 |
| `/legislatures/{legislature}/bills` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/bills` | 200 |
| `/legislatures/{legislature}/chamber` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/chamber` | 200 |
| `/legislatures/{legislature}/committees` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/committees` | 200 |
| `/legislatures/{legislature}/emergency-powers` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/emergency-powers` | 200 |
| `/legislatures/{legislature}/institution-acts` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/institution-acts` | 200 |
| `/legislatures/{legislature}/oversight` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/oversight` | 200 |
| `/legislatures/{legislature}/referendums` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/referendums` | 200 |
| `/legislatures/{legislature}/session` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/session` | 200 |
| `/legislatures/{legislature}/sessions` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/sessions` | 200 |
| `/legislatures/{legislature}/settings` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/settings` | 302 |
| `/legislatures/{legislature}/speaker` | legislature | `/legislatures/276df6ae-053a-4b34-a8f4-f472df0ae984/speaker` | 200 |
| `/organizations/{organization}` | organization | `/organizations/d6ca66d5-0543-4ba3-b717-14a6f72d8503` | 200 |
| `/organizations/{organization}/board-elections` | organization | `/organizations/d6ca66d5-0543-4ba3-b717-14a6f72d8503/board-elections` | 302 |
| `/organizations/{organization}/cgc` | organization | `/organizations/d6ca66d5-0543-4ba3-b717-14a6f72d8503/cgc` | 500 |
| `/organizations/{organization}/delegations` | organization | `/organizations/d6ca66d5-0543-4ba3-b717-14a6f72d8503/delegations` | 302 |
| `/organizations/{organization}/economy` | organization | `/organizations/d6ca66d5-0543-4ba3-b717-14a6f72d8503/economy` | 302 |
| `/rooms/board/{board}` | board | `/rooms/board/01a076a0-4ae8-73b6-9832-b195516212ce` | 302 |
| `/rooms/chamber/{legislature}` | legislature | `/rooms/chamber/276df6ae-053a-4b34-a8f4-f472df0ae984` | 200 |
| `/rooms/committee/{meeting}` | meeting | `/rooms/committee/019fae79-aceb-73a8-a87a-8e25969f1e62` | 200 |
| `/rooms/court/{case}` | case | NO SAMPLE (missing case) | — |
| `/setup/step/{n}` | n | `/setup/step/0` | 200 |
| `/support/ticket/{ref}` | ref | NO SAMPLE (missing ref) | — |
| `/system/translations/review/{locale}` | locale | `/system/translations/review/es` | 302 |
| `/vacancies/{vacancy}` | vacancy | NO SAMPLE (missing vacancy) | — |

## Machine endpoints (87) — not pages

Excluded from the page sweep by rule. Reason per entry.

| Endpoint | Exclusion reason |
|---|---|
| `/.well-known/cga-federation` | .well-known/ discovery document |
| `/.well-known/matrix/client` | .well-known/ discovery document |
| `/.well-known/matrix/server` | .well-known/ discovery document |
| `/.well-known/openid-configuration` | .well-known/ discovery document |
| `/_matrix/app/v1/rooms/{alias}` | _matrix/ appservice endpoint |
| `/_matrix/app/v1/users/{userId}` | _matrix/ appservice endpoint |
| `/api/background-jobs` | api/ endpoint (JSON) |
| `/api/build/progress` | api/ endpoint (JSON) |
| `/api/cosmic-addresses/default-path` | api/ endpoint (JSON) |
| `/api/cosmic-addresses/{id}/children` | api/ endpoint (JSON) |
| `/api/export/jurisdictions` | api/ endpoint (JSON) |
| `/api/export/jurisdictions/download/{filename}` | api/ endpoint (JSON) |
| `/api/export/jurisdictions/list` | api/ endpoint (JSON) |
| `/api/export/jurisdictions/tables` | api/ endpoint (JSON) |
| `/api/federation/audit-tail` | api/ endpoint (JSON) |
| `/api/federation/checkpoint` | api/ endpoint (JSON) |
| `/api/federation/foundation/page` | api/ endpoint (JSON) |
| `/api/federation/geodata/manifest` | api/ endpoint (JSON) |
| `/api/federation/geodata/seed/page` | api/ endpoint (JSON) |
| `/api/federation/identity` | api/ endpoint (JSON) |
| `/api/federation/write-status/{origin}/{key}` | api/ endpoint (JSON) |
| `/api/geodata/flags` | api/ endpoint (JSON) |
| `/api/geodata/repairs` | api/ endpoint (JSON) |
| `/api/geodata/scan/status` | api/ endpoint (JSON) |
| `/api/jurisdictions/activation-status` | api/ endpoint (JSON) |
| `/api/jurisdictions/{jurisdiction}/ancestors` | api/ endpoint (JSON) |
| `/api/jurisdictions/{jurisdiction}/children.geojson` | api/ endpoint (JSON) |
| `/api/jurisdictions/{jurisdiction}/self.geojson` | api/ endpoint (JSON) |
| `/api/jurisdictions/{jurisdiction}/siblings.geojson` | api/ endpoint (JSON) |
| `/api/jurisdictions/{jurisdiction}/subtree-progress` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/districts-at` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/maps` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/mass-status` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/revealed.geojson` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/type-b-map/revealed.geojson` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/type-b-map/status` | api/ endpoint (JSON) |
| `/api/legislatures/{legislature_id}/wizard-steps` | api/ endpoint (JSON) |
| `/api/maps/latest-pmtiles` | api/ endpoint (JSON) |
| `/api/me/video-prefs` | api/ endpoint (JSON) |
| `/api/mesh/nearest` | api/ endpoint (JSON) |
| `/api/public-records/legislatures` | api/ endpoint (JSON) |
| `/api/rasters/{z}/{x}/{y}.png` | api/ endpoint (JSON) |
| `/api/session/heartbeat` | api/ endpoint (JSON) |
| `/api/setup/bootstrap/status` | api/ endpoint (JSON) |
| `/api/setup/deploy-package` | api/ endpoint (JSON) |
| `/api/setup/state` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/progress` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/pull-progress` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/aggregation_discrepancies` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/orphans` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/parent_assignment_audit` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/population_assignment_audit` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/population_gaps` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/sovereign_territories` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/review/{category}/{jurisdiction}/detail` | api/ endpoint (JSON) |
| `/api/setup/wizard/step2/sources` | api/ endpoint (JSON) |
| `/api/setup/wizard/step3/autoscale-progress` | api/ endpoint (JSON) |
| `/api/setup/wizard/step3/summary` | api/ endpoint (JSON) |
| `/api/setup/wizard/step4/progress` | api/ endpoint (JSON) |
| `/api/setup/wizard/step5/progress` | api/ endpoint (JSON) |
| `/api/simworld/progress` | api/ endpoint (JSON) |
| `/api/simworld/rails` | api/ endpoint (JSON) |
| `/elections/{election}/results.csv` | csv file download |
| `/federation/cluster/sync-progress` | sync-progress poll (JSON) |
| `/horizon/api/batches` | horizon/ dashboard data endpoint |
| `/horizon/api/batches/{id}` | horizon/ dashboard data endpoint |
| `/horizon/api/jobs/completed` | horizon/ dashboard data endpoint |
| `/horizon/api/jobs/failed` | horizon/ dashboard data endpoint |
| `/horizon/api/jobs/failed/{id}` | horizon/ dashboard data endpoint |
| `/horizon/api/jobs/pending` | horizon/ dashboard data endpoint |
| `/horizon/api/jobs/silenced` | horizon/ dashboard data endpoint |
| `/horizon/api/jobs/{id}` | horizon/ dashboard data endpoint |
| `/horizon/api/masters` | horizon/ dashboard data endpoint |
| `/horizon/api/metrics/jobs` | horizon/ dashboard data endpoint |
| `/horizon/api/metrics/jobs/{id}` | horizon/ dashboard data endpoint |
| `/horizon/api/metrics/queues` | horizon/ dashboard data endpoint |
| `/horizon/api/metrics/queues/{id}` | horizon/ dashboard data endpoint |
| `/horizon/api/monitoring` | horizon/ dashboard data endpoint |
| `/horizon/api/monitoring/{tag}` | horizon/ dashboard data endpoint |
| `/horizon/api/stats` | horizon/ dashboard data endpoint |
| `/horizon/api/workload` | horizon/ dashboard data endpoint |
| `/horizon/{view?}` | horizon/ dashboard data endpoint |
| `/oauth/authorize` | oauth/ endpoint |
| `/oauth/jwks` | oauth/ endpoint |
| `/oauth/userinfo` | oauth/ endpoint |
| `/storage/{path}` | storage/ file |
| `/up` | health probe |



## Correction 2026-09-15 (pass 2)

Three JSON polls outside `/api` were classified as pages by the prefix rule and failed `document-title`: `/system/translations/progress`, `/dev/playtest/state`, `/dev/scenario/state` (all `application/json`, verified with curl). They are now machine endpoints by name. Classes: 56 guest, 42 signed-in, 62 parameterised, 90 machine, 1 viewer-bound; sum 251.

## Correction 2026-09-15 (pass 4)

`/civic/jurisdictions/search` and `/dev/users` answer `application/json` (verified with curl, signed in); they never established as pages and are machine endpoints by name now. Classes: 56 guest, 40 signed-in, 62 parameterised, 92 machine, 1 viewer-bound; sum 251.

## Correction 2026-09-15 (guest re-sweep)

`/continue` is a redirector: it stores the intended URL and always sends the visitor to `/register` or `/login` (routes/web.php). It has no page of its own and is a machine endpoint by name now. Classes: 55 guest, 40 signed-in, 62 parameterised, 93 machine, 1 viewer-bound; sum 251.
