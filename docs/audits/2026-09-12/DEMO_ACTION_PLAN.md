# Demo build punch list

Updated 13 September 2026. **This list contains confirmed missing features and repairs only.** Each row includes the internal test required to finish that build. Checking whether something works is tracked separately; it is not itself a build item.

[Completed work and passed checks](DEMO_COMPLETED_WORK.md) · [Pending internal checks](DEMO_REVIEW_REGISTER.md) · [Human-dependent checks](DEMO_DEFERRED_CHECKS.md) · [Deployment handoff](NEXT_SESSION_HANDOFF.md)

Finish the current build before starting the next. Consolidation comes first, then the remaining institutional actions and supporting features. Rehearse the completed flows with simulated participants before the final language/accessibility review. Setup must then produce both a walkable demo and a player-ready beta. Performance repairs accompany the affected feature.

## Progress since the instruction to work through both lists

Fixed baseline: commit `8bf59184`, immediately before this development batch. Closed means development and required internal tests passed; partial review passes do not count as a closed review.

| List | At baseline | New confirmed items | Closed | Remaining |
|---|---:|---:|---:|---:|
| Build punch list | 29 | 4 | 32 | 1 |
| Internal review register | 29 | 0 | 2 | 27 |

The thirty-two closed builds are B3, P2, B4, EO-6, LE-1, EO-7, B5, EO-1, E5, IO-6, EO-8, EO-2, EO-3, B6, EO-4, EO-5, IO-1, IO-2, IO-3, IO-4, IO-7, IO-5, S2, LE-2, LE-3, AC-1, G1, G2, G3, M3, M4 and M5. The two closed reviews are S3 ownership concurrency and L1 lesson completion/awards/stipends. Added builds are B5, EO-8 and B6 (already closed), and IO-7 (open), each tied to a demonstrated defect or missing action. EO-4 nomination, committee designation, confirmation and seating are now integrated and internally tested. The separately requested map-sidebar cleanup is completed extra work, outside this fixed baseline. These are item counts, not a percentage of effort or a cost forecast. Earlier completed work remains in the archive and is excluded from this fixed-baseline comparison.

## 1. Complete the institutional action paths

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|

Evidence: [board audit](ORGANIZATION_BOARD_AUDIT.md), [election and institutional checks](../2026-09-13/ELECTION_OFFICE_CHECKS.md), [interjurisdictional/setup audit](SETUP_AND_SCENARIO_AUDIT.md). E4 board-chair participation is completed and has moved to the archive.

## 2. Education management and achievement integration

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|

Evidence: [education/achievement checks](../2026-09-13/EDUCATION_ACHIEVEMENT_CHECKS.md). The existing profile tab, catalog, lesson registry and video selectors are recorded as implemented and checked where evidence supports it. Do not rebuild them. Full language, lesson accuracy and accessibility checking is in the review register; demonstrated failures become specific repairs here.

## 3. Setup, mesh and scale

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| M6 | Bound foundation import finalization and progress totals. | Authority stamping and completion verification run in bounded pages/checkpoints; total progress does not require repeated world-table scans. Mirrors claim no authority. |

Evidence: [setup/scenario audit](SETUP_AND_SCENARIO_AUDIT.md), [mesh/setup checks](../2026-09-13/MESH_SETUP_CHECKS.md). The public Matrix state/configuration repairs M1/M2 and term-creation repair Q1 are archived after their targeted passes; full fresh-install and two-node acceptance remain internal checks.

All tests use isolated fixtures, queues and rooms. Step 5/Dev sequences may seed those fixtures; do not rerun, reset or revert the existing completed world. Move each completed build immediately to the archive. A remote-host dependency is an internal check dependency, not a human-only deferral.
