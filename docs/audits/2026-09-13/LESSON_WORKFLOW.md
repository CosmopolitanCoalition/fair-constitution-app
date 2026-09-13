# Lesson grading, completion, awards and payments

13 September 2026 — internal review L1 lessons completed for the scope below.

## Executed workflow

`tests/Integration/IsolatedLessonWorkflowTest.php` runs the real GradingService, LearnController check action, ConstitutionalEngine, completion handler, AchievementService, TrainingStipendService, AccountService, IssuanceService, LedgerService and AuditService on PostgreSQL. Global role derivation and settings lookup are fixture doubles; the synthetic learner receives R-01, the configured fixture payment is 10 and its funding source is minted. No controller/engine success or payment result is mocked.

The explicitly created database `codex_lessons_152e06db6456` was empty before each test. The test requires the exact disposable-name pattern, validates `current_database()`, rejects an existing public-table schema, creates only synthetic tables inside a transaction and rolls them all back. It does not run migrations, invoke world simulation, dispatch queues or use real identities. The database is removed after the run. This is application workflow evidence, not a virgin-install or production-schema-trigger test.

**3 tests / 70 assertions passed** (2.934 seconds, 18 MB reported process memory):

- Incorrect answers return weighted score and teaching references without creating failed-attempt progress, achievement, audit or payment rows.
- A passing answer set at the configured threshold files the actual completion, writes private progress and its once-only achievement, mints and pays exactly 10.000000, and makes the accepted training record visible to TrainingGateService.
- A retake updates score while preserving the original completion time. A second lesson completes independently. Neither duplicates the person's award or stipend.
- Completion records contain only module, track, pass and score, with the correct actor. The actual audit and money chains verify from the stored PostgreSQL records.
- An injected PostgreSQL payment constraint causes the whole filing to roll back: progress, award, audit append, issuance and balances. Removing that fixture constraint allows a clean single payment on retry.
- Missing, deleted, unpublished and empty-answer modules never pass grading.

## Repaired defect

GradingService previously selected a draft module's answer keys and returned a passing grade. The completion handler then refused the unpublished module. A failing fixture reproduced the grading mismatch. Grading now requires both the track and module to be live, matching the existing lesson reader and completion handler. No pass threshold or eligibility rule changed.

Additional regression group: **17 tests / 4,801 assertions passed** across EducationAnswerKeySecrecyTest, EducationProgressNeverFederatesTest, EducationNoGateTest and LearnProfileReadFixtureTest. These comprise source/contract checks, private SQLite reader checks and one read-only schema inspection of the live `education_progress` column list. No live-world record was changed.

## Reproduction

Create a new empty PostgreSQL database named `codex_lessons_` followed by 12 random hexadecimal characters. Run the test inside the app container with `WOS_LESSON_FIXTURE_DB` set to that exact name. The test deliberately skips without explicit fixture configuration. Verify the database name and that no fixture tables remain before removing only that disposable database. Never point this test at the simulated world.

Remaining education work is LE-2 persistent content management, LE-3 relevant video associations and AC-1 broader action triggers. Their associated reviews, actual media decoding and settled language/accessibility checks remain in the review register.
