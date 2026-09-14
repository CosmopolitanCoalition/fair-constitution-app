# Demo internal review register

Updated 14 September 2026. **Unfinished checks belong here; only confirmed build/repair needs belong on the [punch list](DEMO_ACTION_PLAN.md).** All multi-user checks use simulated participants. A check waiting for a feature, an isolated environment or remote-host access retains that dependency here.

On a pass, move its evidence to [completed work](DEMO_COMPLETED_WORK.md) and remove the pending scope. On a demonstrated defect, add the concrete repair to the punch list and retain unfinished review scope here. Do not count failed, skipped or unexecuted cases as passes. Only human-dependent checks go to [deferred human checks](DEMO_DEFERRED_CHECKS.md).

R2/S1/S3/L1/L2/D1 preserve the former action-plan review scopes. EO/IO dependencies refer to the [election/office audit](../2026-09-13/ELECTION_OFFICE_CHECKS.md); education dependencies to the [education/achievement audit](../2026-09-13/EDUCATION_ACHIEVEMENT_CHECKS.md); M dependencies to the [mesh/setup audit](../2026-09-13/MESH_SETUP_CHECKS.md).

## Civic, economic and room workflows

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| R2 | Combined institutional rooms over real transport | A host browser run (SFU_WS=ws://localhost:7880 SFU_HTTP=http://localhost:7880) or a host where the SFU's advertised node IP is reachable; Mesh/rooms · TLS for the public overlay | Signaling half established ([evidence](../2026-09-14/BROWSER_REVIEW.md)): app-minted grants accepted by the real SFU, forged and expired grants refused, real Matrix round-trip, door refusals with zero transport calls, three identities joined from Chromium. Remaining: media frames both directions, interruption and rejoin with media resuming, and the removed member's live connection loss, blocked inside the container by the SFU node IP 127.0.0.1 and blocked STUN. |
| S1 · consistency | Care and usability across the above surfaces | Compiled prop-driven companions for the 27 S1 action-door pages that have none (Executive has none); browser-level checks belong to L2 | Navigation context holds across the full route inventory and the shared loading, error, disabled, return-path and accessible-interaction conventions hold on the 14 swept surfaces ([evidence](../2026-09-14/ORGANIZATIONS_REVIEW.md)). Remaining: DOM-level acceptance of the 27 uncovered action-door pages, pinned as a boundary in `NavRoleGateParityTest`. Route/source presence alone does not establish UI acceptance. |

## Learning, languages and accessibility

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| L1 · languages | Settled conference-language scenario coverage | Punch list LG-1, LG-2 and LG-3 (absent namespace catalogs, untranslated keys, player internationalization); rubric `translation-first-pass-provider` | Instrument in place ([evidence](../2026-09-14/EDUCATION_REVIEW.md): `tests/js/i18nCoverage.test.mjs`, 5 recorded defects). Re-run after the translations land: every English namespace file present per conference locale, zero missing keys, player labels and errors through vue-i18n. Human translation naturalness remains separate. |
| L2 | Accessibility and modalities | Punch list A11Y-1 (67 static accessible-name gaps), A11Y-2 (contrast token and opacity), A11Y-3 (prose links), A11Y-4 (table focus, bootstrap title); a host with more browser memory for four routes | Both passes ran ([evidence](../2026-09-14/BROWSER_REVIEW.md), [static](../2026-09-14/EDUCATION_REVIEW.md)): keyboard focus and names, narrow layout, media alternatives and lang/dir hold on 34 guest routes; contrast and two semantics rules fail with 1,044 axe nodes recorded; the static scan pins 67 gaps as a baseline. Remaining: re-run both scans green after the repairs; establish /tour, /coverage-ops, /legislatures and /system/public-records on a larger host; human assistive-device experience stays separate. |

## Setup, mesh and scale

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| Setup · schema | Virgin PostgreSQL/PostGIS install and restart | Uniquely named disposable Compose project (rubric `second-compose-project-box-e` = B: a Linux host or the cloud box) | Database half passed ([evidence](../2026-09-14/EDUCATION_REVIEW.md): baseline load, additive migrate, no-op second run, singletons, `federation:init` twice on a disposable database). Remaining: federation identity persists across a full restart of a disposable Compose project. |
| Setup · installer | Linux/Windows failure/retry contracts | Linux Docker host for the cold container install; rubric `windows-public-deploy-policy` for the deploy.ps1 public-Matrix gate (punch list DP-1) | Stub contracts passed for deploy.sh and deploy.ps1 ([evidence](../2026-09-14/EDUCATION_REVIEW.md)). Remaining: stub success followed by an actual container installation on a disposable Linux host. |
| Mesh · join | Two-node volunteer admission/replication | Distinct app/signing identities, small isolated app/PostGIS fixtures, queues/caches/endpoints; M3/M5/M6 | Keyed join or simulated host approval, signed foundation/audit transfer, authority preservation, operator access and useful replicated browsing succeed. Tampered pages refuse. Disconnect resumes the same membership and completed pages without duplicate identities or local authority claims. |
| Setup · worlds | Walkable demo and player-ready beta | G1/G2; settled civic/economic flows; isolated Step 5/Dev sequences | Validate the two modes separately: representatives, ownership structures, boards/chairs and scenario prerequisites exist. Readiness reports residue/exclusions honestly. Pre-enrolled early pages do not hide later missing cohorts; repeated resumes complete without duplicates. |
| Scale | Geodata, maps, provisioning, simulation and progress | Deterministic small fixtures, then larger disposable-host fixtures; bounded stage/item timers | Compare cold preparation, partial resume and reuse of accepted geometry/maps. Record peak memory, statement duration, throughput and concurrent progress-poller latency while preserving correctness, apportionment and host-derived capacity. Include board-backstop failure visibility/demo timing. Counting-only performance does not establish setup performance. |
| Mesh/rooms · TLS | Fresh public-host transport harness | Disposable Linux host or isolated TLS harness; separate Matrix/MAS/LiveKit users/rooms/URLs | Synthetic clients complete secure login, discovery, signaling, media exchange and disconnect/rejoin with history retained. DNS/certificate/network failures report truthful state. Use no live conference room or peer as a fixture. |

Follow the isolation requirements and reusable sequences in the [setup/scenario audit](SETUP_AND_SCENARIO_AUDIT.md). Inspect named/default database connections and live-Postgres helpers; a SQLite environment flag alone is insufficient. Isolate Redis, queues, files, identities and network endpoints too. Do not reset or rerun the existing world as a test.

## Conference host and final release

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| Host · rollout | Release installation on the separate Linux conference host | Release ready; host access/deployment owner; [handoff](NEXT_SESSION_HANDOFF.md) | Pull/install the intended release, apply additive migrations, refresh relevant caches/workers and verify running revision, login, required services and preserved state. Waiting for host access is not a human-only deferral. |
| Host · internet | Public signaling, Matrix and ICE/TURN connectivity | Deployed conference release; public URLs, certificates, DNS and network configuration | Separate synthetic internet clients connect through the public endpoints, exchange media, recover from disconnect and reach retained Matrix history. Local/harness passes do not establish this host's connectivity. |
| D1 | Complete final simulated presentation | Setup · worlds, Mesh/rooms · TLS and Host · rollout/internet (deferred by the 2026-09-14 rulings); the S1 predecessors | Runner prepared ([evidence](../2026-09-14/BROWSER_REVIEW.md): `docs/demo/D1_REHEARSAL_PLAN.md`, `scripts/demo/d1_rehearsal.mjs` with --plan and --dry-run, action steps refuse without a demo session). Remaining: the complete simulated presentation itself, navigation, jurisdiction tree and maps, civic and economic actions, room calls, loading feedback and recovery on the intended demo setup. |

Passing evidence stays in completed work and the three source audits. This register does not repeat already completed counting, navigation, registry, media-component, setup-ladder, script-syntax or Compose checks as unfinished work.
