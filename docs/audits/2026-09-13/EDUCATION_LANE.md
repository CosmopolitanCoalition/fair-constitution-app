# Education lane (LE-2, LE-3, AC-1)

Date: 2026-09-13. Built in parallel on branch `lane/edu` (worktree `.wt/edu`), each item reviewed by three adversarial lenses and repaired, committed per item, merged into main as `c68a8717`. Migrations applied on box E after the merge.

## LE-2 lesson publication (commit 5be73f05). Closed.

Ruling `lesson-publication-shape` = A (structural publish). F-EDU-002 writes the `education_modules` row through `EducationCatalogService::publishModule` inside the engine transaction (title, surface, status, revision_number, published_by, published_at); one private upsert helper serves the seed path and the publish path, so the service stays the single writer. Revise increments the revision and refuses an unknown key. The handler awards nothing and pays nothing; the answer-key refusal is untouched; a demo publication is captured for reversal like any demo write. An R-23 editor page at `/learn/manage` lists modules and files F-EDU-002 or revise through the engine; non-editors get a read-only preview. Two surfaces registered and authored in the K-2 source. Migration `2026_09_13_180000_education_module_publication.php` (three additive columns).

Reviewers caught a stale storage-free assertion in `EducationFormsTest` (now refusal-only; the accept path is owned by `MaterialPublicationTest`). Left as a low flag: the handler accepts any registered surface, not only education surfaces.

## LE-3 lesson videos (commit 844cde20). Closed.

Ruling `lesson-video-association` = A with the operator's note. The K-2 source carries a document-level default video and seven per-surface overrides; the generator validates every id against the catalog and emits a video field per surface plus a PHP-readable `education.videos.json`. The lesson page renders the multi-track player above the authored steps; a plain note appears only when the film is the demo default. The Learn flyout deep-links `/videos?v=<id>` and the library pre-selects it. The operator swaps the default id for his recorded demo video. No DB, no form, the player untouched. No findings in review.

## AC-1 achievement wiring (commit a23c8ec8). Closed.

Ruling `achievement-wiring-scope` = A. Self awards in 43 engine handlers inside the existing engine transaction, so a refused or rolled-back filing awards nothing; subject awards where the earner differs from the filer (petition creator at threshold, endorsed candidate, validated candidate, referendum creator, IP dedicator, supermajority trigger); state awards by `achievements:sweep`, keyset-chunked with a host-derived chunk, resumable through `achievement_sweep_cursors`, scheduled daily; immediate awards at committee and judicial seat minting. Demo awards are captured like any write: the append-only `achievements` trigger now admits exactly one case, a deleted_at stamp under the transaction-local `cga.demo_void` flag that only `DemoSessionService::reverse` sets. Plane B (jurisdiction and system milestones) deferred as ruled. Wiring table in `docs/plans/education/K2_ACHIEVEMENT_WIRING.md`. Migrations `2026_09_13_182000_achievement_sweep_cursors.php` and `2026_09_13_190000_achievements_demo_void_soft_delete.php`.

## Passed checks on the merged main

| Run | Result |
| --- | --- |
| `MaterialPublicationTest`, `LessonVideoTest`, `AchievementWiringTest` with `AuditChainSmokeTest`, `TrainingGateTest`, `EducationNoGateTest`, `EducationAnswerKeySecrecyTest`, `EducationFormsTest` and the other lane and pin sets | OK (155 tests, 5,706 assertions, 1 expected live-pin skip) |
| Full compiled-Vue suite | 195 pass, 0 fail |
| Vite transform `MaterialManager.vue`, `MaterialEdit.vue`, `Lesson.vue` | 200 |
| `node scripts/education/build_education_payload.mjs` after merge | OK, idempotent |
| Migrations on box E | 3 DONE, no invalid index |

Pulling hosts: apply the three migrations with the code, refresh workers, schedule picks up `achievements:sweep`.
