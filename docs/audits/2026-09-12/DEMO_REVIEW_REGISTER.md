# Demo internal review register

Updated 14 September 2026. **Unfinished checks belong here; only confirmed build/repair needs belong on the [punch list](DEMO_ACTION_PLAN.md).** All multi-user checks use simulated participants. A check waiting for a feature, an isolated environment or remote-host access retains that dependency here.

On a pass, move its evidence to [completed work](DEMO_COMPLETED_WORK.md) and remove the pending scope. On a demonstrated defect, add the concrete repair to the punch list and retain unfinished review scope here. Do not count failed, skipped or unexecuted cases as passes. Only human-dependent checks go to [deferred human checks](DEMO_DEFERRED_CHECKS.md).

R2/S1/S3/L1/L2/D1 preserve the former action-plan review scopes. EO/IO dependencies refer to the [election/office audit](../2026-09-13/ELECTION_OFFICE_CHECKS.md); education dependencies to the [education/achievement audit](../2026-09-13/EDUCATION_ACHIEVEMENT_CHECKS.md); M dependencies to the [mesh/setup audit](../2026-09-13/MESH_SETUP_CHECKS.md).

## Civic, economic and room workflows

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| R2 | Combined institutional rooms over real transport | Separate fixture identities and actual private Matrix/LiveKit connections with generated media | Join the same app-authorized presider/member/witness/board journey to real transport. Verify media continuity, connection interruption/rejoin, retained identity/floor/history, direct unauthorized joins and removed members with already-issued grants/connections. The combined application/controller/seating scope passed and moved to Completed; synthetic HTTP/track objects do not establish this remaining transport lifetime scope. |
| S1 · consistency | Care and usability across the above surfaces | Compiled prop-driven companions for the 27 S1 action-door pages that have none (Executive has none); browser-level checks belong to L2 | Navigation context holds across the full route inventory and the shared loading, error, disabled, return-path and accessible-interaction conventions hold on the 14 swept surfaces ([evidence](../2026-09-14/ORGANIZATIONS_REVIEW.md)). Remaining: DOM-level acceptance of the 27 uncovered action-door pages, pinned as a boundary in `NavRoleGateParityTest`. Route/source presence alone does not establish UI acceptance. |

## Learning, languages and accessibility

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| L1 · education | Surface guidance and lesson/video integration | Learn/content-management builds; actions settled | Guidance describes current controls and uses the intended Learn home. Check new work/help/rooms/video pages and explicit non-V2 setup/auth/operator/dev layouts. Published lesson/video links resolve. Registry ID coverage alone does not establish accuracy. |
| L1 · achievements | New action awards and catalog integration | Isolated action/form/award fixtures; corresponding event triggers built | Verified actions award the proper person idempotently; refusals/retries do not award incorrectly. New awards integrate with the catalog/profile and confer no powers. Existing catalog/earner tests and public/private/self profile-read checks are completed evidence. |
| L1/L2 · video | Actual browser media decoding | Controlled local master/dub/VTT assets; automated browsers | Play/pause/seek, language switching during playback, ending, buffering, failures, caption timing and remembered preferences work. The 12 passing synthetic player component tests already close their repaired defects; this is the broader decoding check. |
| L1 · languages | Settled conference-language scenario coverage | Features/scenarios fixed; selected conference languages and settled strings | Inspect missing/fallback strings and cover actual scenarios, lessons, controls/errors and audio/language fallbacks. Human translation naturalness remains separate. |
| L2 | Accessibility and modalities | Settled workflows and language/media surfaces | Keyboard/focus, screen-reader semantics, narrow layouts, contrast, media alternatives and language/audio fallbacks pass internal inspection/automation. Demonstrated failures become repairs. Human assistive-device experience does not replace these checks. |

## Setup, mesh and scale

| Review | Workflow | Dependency | Pass criterion |
|---|---|---|---|
| Setup · schema | Virgin PostgreSQL/PostGIS install and restart | Uniquely named disposable Compose project/network/storage; isolated configuration | Baseline and additive migrations succeed; second migration run is a no-op. Required schema/default records exist; federation identity persists across restart. Current-world migration history is not fresh-install evidence. |
| Setup · installer | Linux/Windows failure/retry contracts | M1–M5 repairs; disposable filesystem/process stubs, then disposable cold install | Ready/not-ready, config generation failure/missing output, custom/existing project, key preservation and interrupted join/retry produce correct state/exit and readable recovery. No destructive fallback or ordinary-resume identity regeneration. Stub success is followed by actual container installation. |
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
| D1 | Complete final simulated presentation | Preceding required repairs/checks complete; intended demo setup available | Navigation, jurisdiction tree/maps, civic/economic actions, room calls, loading feedback and recovery work together through the whole presentation. Record actual outcomes and repair failures. Human attendance is not required. |

Passing evidence stays in completed work and the three source audits. This register does not repeat already completed counting, navigation, registry, media-component, setup-ladder, script-syntax or Compose checks as unfinished work.
