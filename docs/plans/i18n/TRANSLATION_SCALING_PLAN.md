# Translation Scaling Plan — Phase N string layer (2026-07-25)

Retrofit the autoscale pull-engine pattern (claim ladder → worker pool → pump liveness →
review/requeue → one acceptance gate) onto **translation**, so the app's body copy — ~5,100
unique source strings across 87 pages, 90 components, and the PHP surface registry — can be
extracted, machine-translated toward **77+ languages**, stored, versioned, and served as a
resumable, haltable, visibly-progressing sweep instead of an unbounded batch job.

Investigation basis (2026-07-25, code-verified): Phase F shipped the i18n *machinery* — vue-i18n
with 5 chrome locales, a deterministic `en-XA` pseudo-locale, a 36-term glossary, RTL plumbing,
K-3's pin-tested `TranslationProvider`/`TranslationGate` seam — and the app then grew **179 `.vue`
files of hardcoded English** on top of it. Only **6 files** use `useI18n`, and only **17 keys** are
actually called against a 111-key catalog. The per-namespace catalog directory the loader already
globs **does not exist**. The four non-English catalogs are **8 keys behind** and nothing detects
it. Everything missing is the *pipeline*; almost nothing missing is the *machinery*.

**STATUS: PLAN ONLY — nothing here is built. Build order in §9.**

---

## 0. Two findings that shape everything below

**`public_records` is append-only at the database level.** `CREATE TRIGGER public_records_immutable
BEFORE DELETE OR UPDATE ON public.public_records FOR EACH ROW EXECUTE FUNCTION
public_records_block_mutation()` (`database/schema/pgsql-schema.sql:10842`, function at `:262`).
`PublicRecordService`'s own docblock says it: *"a back-filling UPDATE would be blocked by the
immutability trigger"* (`:19-21`). Therefore the `public_records.translations` jsonb **can never be
back-filled after insert**. Every "sweep the records table and fill in the jsonb" design is dead on
arrival. The lawful shape is a side store the read path merges — §7.

**Haiku 4.5's minimum cacheable prompt is 4,096 tokens.** A glossary + style + do-not-translate
prefix smaller than that silently does not cache — no error, just `cache_creation_input_tokens: 0`.
At ~128 requests per language a ~4.5k-token uncached prefix costs **$0.58/language**, more than the
entire source corpus; cached it costs **$0.06**. Hitting the cache floor deliberately is a design
requirement of the batching scheme, not an optimization to add later.

---

## 1. The run model — phases and items

A translation run is a phase DAG: parallel fan-outs separated by single-writer barriers, exactly
the autoscale claims-ladder shape.

Run phases (enum on `translation_runs.phase`):

```
enumerating → glossary → translating → verifying → publishing → done
```

Item kinds (on `translation_items.kind`) and their pools:

| Phase | kind | Item = | Count | Bound by |
|---|---|---|---|---|
| enumerating | `manifest` | scan catalogs + memory, mint every item, fill `est_tokens` | 1 | disk / PG |
| glossary | `glossary_seed` | one locale's 38 constitutional terms, seeded **before** any prose | 1 per locale | human |
| translating | `catalog_batch` | one (locale × namespace) slice, ~40 strings | ~20 ns × N locales | provider |
| translating | `record_batch` | one (locale × 200 normalized record templates) slice | volume-driven | provider |
| verifying | `qa_scan` | one locale's full QA suite over its own output | 1 per locale | CPU |
| publishing | `publish_locale` | flip a locale live on zero-error QA | 1 per locale | PG |

**One engine, two kinds** — not two engines. `catalog` and `record` work shares the claim ladder,
the lease strip, the halt flag, the breaker, the revert law and the progress endpoint; only the
processor differs. This is the `autoscale_items.kind` precedent (`sweep` | `single`), not a new
pattern, and it is what lets one dashboard show the whole translation plane.

**SETTLED DECISIONS:**

- **Glossary before prose.** The charter's named top human task. `glossary_seed` is a hard barrier
  *per locale*: no `catalog_batch` for locale L is claimable until L's seed is `done`. This is the
  only cross-item dependency in the ladder — everything else is embarrassingly parallel.
- **The enumeration latch.** THE ETL RULE mints items in bounded committed chunks, which means a
  pool can *look* drained while later chunks are still landing — and a phase that advances on
  "zero pending+running" would then publish a locale at partial coverage. The `manifest` item
  therefore stamps `translation_runs.enumerated_at` in the same transaction as its final chunk,
  and **no phase advances while it is NULL**. The pump's enumeration-repair duty keys off that
  stamp rather than off a zero row-count, so the repair terminates instead of rescanning forever.
- **Completion = zero OPEN work**, mirroring `AutoscalePumpCommand` exactly (`open_items === 0 &&
  open_scopes === 0`), never a `done == total` equality. `review`, `refused` and `failed` are
  counted separately precisely so they can never block completion — a done-count test would let
  one string that ate six provider timeouts strand a 99.9%-finished run forever, with the pump
  seeding workers every minute against nothing claimable and `revert` refusing because the run is
  not halted.
- **Emission is separate from publication.** A locale's catalog is emitted once its units are
  settled, with QA-failing keys **absent** (so the loader falls back to `en` — a missing key is a
  visible English string, not a broken page). `publish_locale` is the separate gate that carries
  the operator's zero-error rule. Conflating the two either strands runs or auto-publishes failed
  QA; splitting them makes both properties true at once.
- **Ordering: CHEAPEST-FIRST** (`position` by `est_tokens` ASC) — autoscale's simplest-first
  posture, the opposite of the geodata engine's largest-first. Translation has real triage benefit:
  small namespaces finish and publish while `Pages/Legislature` (932 strings) is still running, so
  coverage bars move early and a bad prompt is caught on a cheap slice.
- **Failures never sink the run** (autoscale posture): a phase's barrier opens when its pool has
  zero pending+running — `done`, `review`, and `failed` all count as settled. A refused locale's
  absence is honest, and its `qa_scan` says so.
- **The faction-language freeze is a claim-path gate, not a review note.** Fleet-wide freeze holds
  until lane 15's corrected-wording list lands. `translation_memory.frozen_reason` is non-null for
  any key whose English source is on that list; frozen keys are never claimed, and `publish_locale`
  refuses while one remains unresolved. A machine pass multiplies wrong copy into 77 languages and
  is far more expensive to retract than to delay.
- **`dry_run` on reprocess**: a requeued batch with `dry_run=true` writes metrics + flags instead of
  catalog/memory writes, preserving a review-then-apply loop for prompt iteration — the T.7
  discipline, ported.

---

## 2. Schema (one REAL-dated additive migration)

`2026_07_XX_000001_translation_pull_engine.php` — four tables, mirroring autoscale's proven shapes.
**Design law honored** (`2026_07_19_000001:28-31`): the run-scoped worklist carries `run_id`; the
input-derived **translation memory carries none**, because it is derived from source text and must
survive every revert iteration.

- **`translation_runs`**: `id` uuid, `status`
  (`queued|enumerating|translating|done|halted|failed`), `phase`, `options` jsonb (locale filter,
  kinds, dry_run, provider), counters (`items_total/done/review` per kind, refreshed by the pump),
  **`halt_requested_at`** timestamptz — *the DB halt flag*, `paused_until` — *the breaker*,
  `pg_fingerprint`, **`budget_tokens_cap`** + `budget_tokens_spent` — *the cost rail*,
  `initiator_user_id`, `phase_timestamps` jsonb, `last_error`, timestamps.
- **`translation_items`**: `id`, `run_id` FK cascadeOnDelete, `kind`, `locale`, `namespace`
  (nullable), `status` (`pending|running|done|review|failed`), `claim_token` uuid, `position`,
  `est_tokens` bigint, `attempts` smallint, `dry_run` bool, `metrics` jsonb (tokens in/out,
  provider, elapsed_ms, QA counts), `reason` text, `started_at`/`finished_at`, timestamps.
  Indexes: `(run_id, status, position)` partial `WHERE status='pending'` — **the claim index**;
  `(run_id, kind, locale, status)` — **the dashboard bar index**.
  `UNIQUE(run_id, kind, locale, namespace)` — the idempotent re-mint key.
- **`translation_worker_leases`**: `id` uuid PK (= the claim token), `run_id`, `started_at`,
  `last_seen_at`, `claim_type` varchar(16), `claim_label` varchar(160), `claim_started_at`,
  `lane` varchar(16). **Byte-compatible with the autoscale lease display**, so the Step-3 worker
  strip renders this engine unchanged — the same compatibility lane 1's geodata plan declares
  (`GEODATA_PULL_ENGINE_PLAN.md:84-86`). Three engines, one worker strip.
- **`translation_memory`** — **no `run_id`**: `id`, `source_hash` char(64) (sha256 of the
  *normalized* source, §7), `locale`, `source_text`, `translated_text`, `provider`, `status`
  (`machine|reviewed|locked`), `is_private` bool, `quality_flags` jsonb, `hits` int,
  `frozen_reason` text null, `verified_by` uuid null, `verified_at`, timestamps.
  **`UNIQUE(source_hash, locale, is_private)`** — `is_private` is part of the key, not just a
  column, so a public string translated by the cloud tier can never be *matched* for a private
  unit carrying identical text. Index `(locale, status)`.
  **The privacy rail as a database CHECK**, per the charter:
  `CHECK (NOT (is_private AND provider LIKE 'cloud-%'))` — the rail lives in the schema, not only
  in `TranslationGate`, so no code path can route private text to a cloud provider. Every memory
  lookup additionally carries `AND m.is_private = u.is_private` in its join predicate: without it
  the CHECK fires *inside* the batch UPDATE, aborting the whole statement and livelocking the
  batch, which is a rail that punishes the engine instead of protecting the content.

No changes to any PROTECTED file. `public_records.translations` keeps its existing shape and read
path; the only server change is at publish time (§7).

---

## 3. The claim ladder

`TranslationClaims::next(run, token, lane)` (PHP, for pins) with the identical SQL available to the
Python worker — the ladder is plain SQL so both sides share it, the geodata-plan convention.

1. Honor `paused_until` / `halt_requested_at` / **budget exhaustion** → no claim. The budget check
   sits *in the claim path*, not in a report: a run at its cap claims nothing, which is the only
   way a ceiling actually holds.
2. **Glossary gate** — exclude `catalog_batch`/`record_batch` for any locale whose `glossary_seed`
   is not `done`. **Freeze gate** — a slice containing a `frozen_reason` key is not claimable. Both
   are arithmetic, not vigilance.
3. The claim:

```sql
UPDATE translation_items
   SET status='running', claim_token=:t,
       started_at=COALESCE(started_at, now()), updated_at=now()
 WHERE id = (
       SELECT id FROM translation_items
        WHERE run_id=:r AND status='pending' AND kind = ANY(:kinds)
          AND (kind NOT IN ('catalog_batch','record_batch') OR locale = ANY(:ready))
        ORDER BY position
        LIMIT 1
        FOR UPDATE SKIP LOCKED
 )
   AND status='pending'
RETURNING *;
```

4. **Two lanes**: `local` (NLLB — CPU/GPU-bound) and `cloud` (Haiku — rate-limited and metered).
   The cloud lane is capped at `max(1, ceil(0.3 × workers))`, decided inside a
   `DB::transaction` serialized by `SELECT pg_advisory_xact_lock(hashtext('cga_translation_lane'))`
   — the `HeavyLaneClaimTest` shape — with the same **drain rule**: the cap lifts when no local
   work is pending.

**Batch size = 40 strings per claim**, defended: autoscale's 15,000 is right for set-based SQL,
where a claim is a filter. A translation unit is one provider round-trip, so the size is set by the
provider's context window and by failure blast radius. 40 strings × mean 77 chars ≈ 770 tokens of
payload — comfortably inside Haiku's 200K window — and a failed batch costs at most 40 strings of
redone work.

**The local-tier segment size is PROVISIONAL pending a bench**, and is written that way on purpose:
every "N ms per segment" figure for NLLB is an assumption until measured, and an assumed rate
silently propagates into the segment size, the stale threshold, and the tail wall-clock. Build step
6 reports the measurement as **(model, precision, device, batch size, mean source chars) →
segments/second**, in lane 1's bench-protocol style, and the constants are derived from it — not
the other way round. Until then the local segment is sized adaptively from observed rolling
throughput, floored at "≥5 s of work per claim, ≤120 s of redo".

---

## 4. The worker

`TranslationWorkerJob` — a direct port of `AutoscaleWorkerJob`'s shape: `timeout=0`, `tries=1`,
`onQueue('translation')` on the **`redis-long`** connection (batches exceed `redis`'s 90 s
`retry_after`; the ghost-redelivery lesson at `config/queue.php:76-84` applies verbatim, and with
`tries=1` a ghost redelivery means phantom failures and double spend), `CLAIM_BUDGET_SECONDS=3000`,
`MEMORY_RECYCLE_BYTES=480MB`, `MAX_CONSECUTIVE_FAILURES=3`, lease INSERT + over-dispatch
self-correction, `pcntl` SIGTERM honored at claim boundaries, claim visibility stamped on the lease
row, `releaseClaim()` on failure, lease deleted in `finally`.

**The one justified divergence from autoscale — the failure taxonomy.** Autoscale's work is
CPU+PG-bound and deterministic; machine translation is network-bound and fails transiently. Three
outcomes, three destinations:

| Outcome | Detected by | Item lands | Recovery |
|---|---|---|---|
| **transient** | 429 / 5xx / 529 / timeout / connection | `pending`, `claim_token=null`, `attempts++` | the pump reseeds next minute — **the backoff IS the pump** |
| **permanent** | 400, schema violation, empty output | `review` + reason | the operator requeue recipe (§5) |
| **refused-by-rail** | `TranslationGate` refusal | `review`, reason `privacy_rail` | never retried on a cloud lane |

A worker that hits 3 consecutive transients releases its claim and exits; the next pump tick
re-seeds. This is what preserves *"the pump is the run's ONLY liveness root"* — no in-job `sleep`
holding a worker slot, no self-rescheduling chain, no successor payloads. An item past
`attempts=5` goes to `review` with its reason, so nothing loops forever — `SinglesBatchProcessor`'s
escape hatch (`:184-194`), ported.

Each batch commits in **one transaction**: memory upserts + item `done` + metrics, with

```sql
ON CONFLICT (source_hash, locale) DO UPDATE SET ...
  WHERE translation_memory.status = 'machine'
```

— **a machine pass can never overwrite a `reviewed` or `locked` translation.** One summary audit
append per batch, never one per string (the autoscale audit-cardinality law).

---

## 5. Laravel side

- **`translation:pump`** — `Schedule::command('translation:pump')->everyMinute()
  ->withoutOverlapping(10)->runInBackground()->onOneServer()`, mirroring `routes/console.php:39-40`.
  Duty ladder, in order: (0) load non-terminal runs, oldest wins, supersede-dedupe · (1) halt/resume
  state machine, the DB column is the source of truth · (2) breaker tick — the same
  `pg_postmaster_start_time() || stats_reset` fingerprint, **pause-only, never a governor** ·
  (3) **budget tick** — read the append-only spend ledger (below); at cap, park the run ·
  (4) stale-claim reclaim (>30 min → `pending`, token cleared) · (5) phase advance when a pool
  drains — **advance lives in the pump, never in a worker** · (6) enumeration repair
  `INSERT..SELECT..ON CONFLICT DO NOTHING` · (7) lease cull (`last_seen_at < now()-10min`) ·
  (8) worker seeding to `HostCapacity::autoscaleWorkers()` — **the same one limiter**, no second
  dial anywhere · (9) counter refresh, completion, `translation.completed` audit append.
- **Progress endpoint** `GET /api/i18n/translation-progress` — a **fresh GROUP BY per poll**, the
  Step-2/Step-3 doctrine (`SetupController.php:2092-2095`: *real numbers every 2 s, never the
  pump's once-a-minute denormalized copies*). Payload: per-locale bars (`GROUP BY locale, kind`
  with `COUNT(*) FILTER`, index-only on the bar index), per-namespace bars, live items (LIMIT 15),
  review census with reasons (LIMIT 100), windowed rate + ETA (items finished in the last 30 min
  × 2.0; **ETA is suppressed while the rate is 0**, so an early run never divides by zero or shows
  a fictional finish time), the worker strip from leases seen in the last 2 min, and spend-vs-cap.
- **Requeue recipe** — the batch-fix cycle, verbatim in shape from lane 1:

```sql
WITH r AS (SELECT id FROM translation_items WHERE run_id=:r
            AND status IN ('review','failed') [AND locale/kind/namespace filter])
UPDATE translation_items SET status='pending', claim_token=null, reason=null,
       started_at=null, finished_at=null, attempts=0, position=0, updated_at=now()
 WHERE id IN (SELECT id FROM r);
```

  Requeue may target any settled item, `done` included — re-running a locale after a prompt tweak
  is the point.
- **Spend is append-only and is never reconciled from surviving rows.** One immutable ledger row
  per provider response (`run_id`, item, unit count, input/output tokens, cost, outcome), and
  `budget_tokens_spent` = `SUM(ledger)` — a number a reclaim, a retry or a revert can only
  increase. Money leaves the account per API call, not per surviving row: a segment stale-reclaimed
  at 30 minutes and re-translated is **two billed calls and one row**, so any scheme that recomputes
  spend from the rows that happen to exist silently erases real spend and lets the claim-path
  ceiling be exceeded without bound. Per-item cost stays as an attribution field for the review UI.
  Transient failures whose response consumed tokens are charged, because the provider charged them.
  **The pump reconciles counters, never money.**
- **`translation:revert {--locale=} {--run=} [--keep-reviewed] [--resume] [--force]`** — the
  adopt-never-bulldoze analogue, and the reason provenance lives in a column. Guards: the run is
  `halted|done` unless `--force`; zero live leases in the last 2 minutes unless `--force`.
  **Deletes only `translation_memory` rows with `status='machine'`.** `reviewed` and `locked`
  translations, every human correction, and every operator-supplied string survive *by
  construction* — there is no flag to get wrong. Items reset to `pending`; the glossary, the
  hash-chained audit log, and the whole verified corpus are kept. Appends `translation.reverted`.

---

## 6. The corpus tooling — `scripts/i18n/**`

| Script | Language | Does |
|---|---|---|
| `extract.mjs` | Node ESM | AST extractor (`@vue/compiler-sfc` + `@babel/parser`) |
| `check.mjs` | Node ESM | the gate: key parity, orphans, unextracted strings, glossary adherence, placeholder parity, stale generated registry |
| `languages.py` | Python 3.12 | **the ONE locale registry** → `config/locales.php` + `resources/js/i18n/locales.generated.js` |
| `translate_catalog.py` | Python 3.12 | provider-routed machine pass, driven by the existing `supervisor.py` |

**The mixed-language question, resolved.** Extraction parses Vue SFCs — Node's job, and the charter
names `.mjs`. Translation runs inside the ETL container's Python because NLLB is a Python model,
and the charter names `translate_catalog.py` driven by `supervisor.py`. `scripts/i18n/` is therefore
the first mixed-language script dir; `scripts/i18n/README.md` documents both halves in
`scripts/etl/README.md`'s format (script table, quickstart, flag reference), and the Python files
follow house style exactly: module docstring as the CLI contract, box-drawn path constants,
container-absolute paths, shared `/etl/etl.log`, paired `--fresh`/`--resume`, atomic control-file
writes, invoked via `docker compose run --rm etl`, never `exec`.

**Extractor spec — the measured shapes**, so the AST work is specified rather than discovered:

- **Inline glue.** `strong em b i span a code small abbr sup sub br Link Icon FormChip TagChip
  CitationLine` are merged, not treated as message boundaries — otherwise a sentence wrapping a
  `<Link>` (`Pages/System/Clocks.vue:176-177`) fragments into three untranslatable pieces.
- **Text-bearing attributes**, static and `:`-bound-with-literal. `Auth/Register.vue:119` hides two
  messages inside one ternary-bound `:label`; a static-attribute scanner misses both.
- **`<script setup>` literals** — 1,055 of them: column/tab label banks, enum→copy maps, state
  machine labels, `announce()` strings.
- **Interpolation-mixed nodes** — 645 — become messages with named placeholders.
- **Pluralization** — ~62 hand-rolled ternaries today and *no* `$tc` anywhere; the floor is higher
  than the count, because `{{ n }} clocks` with no singular branch is grep-invisible and currently
  renders "1 clocks". These become plural sets driven by the registry's per-locale CLDR rules.

**The message format is vue-i18n's, NOT ICU — and this is a correctness rail, not a preference.**
The repo runs `vue-i18n ^11.4.5` and `createI18n` (`resources/js/i18n/index.js:81-97`) registers
**no `pluralRules`**. Three consequences the build must handle, none of which a generic
"ICU placeholders" plan would catch:

1. **`|` is the plural separator, `{` opens an interpolation, and `@:` / `@.` / `@[` open linked
   messages.** Any English source containing a literal `|` (breadcrumb or table copy) silently
   becomes a plural set with the wrong branch rendered, and a literal `{` becomes an unresolved
   interpolation — across ~5,100 strings × 77 locales, **silently**, because `missingWarn` and
   `fallbackWarn` are both `false` today. The extractor therefore **escapes reserved characters on
   emit** (`{'|'}`, `{'@'}`, `{'{'}`).
2. **vue-i18n's built-in choice selector resolves at most three forms.** Arabic has six CLDR
   categories. Without registered `pluralRules` an Arabic 6-form message renders the wrong branch
   for zero/two/few/many at runtime while a segment-counting QA check reports it as correct.
   `languages.py` therefore generates `pluralRules` per locale from the registry's CLDR categories
   and `index.js` passes them to `createI18n` — that file is this lane's to change.
3. **`check.mjs` compiles every source and target with `@intlify/message-compiler`** — the actual
   runtime parser — not an ICU parser, so the gate validates the grammar the app really speaks.

Pinned by an Arabic 6-form message asserted to render the correct branch at n = 0, 1, 2, 3, 11, 100.
- **The component-prop allow-list** — `label`, `title`, `text`, `hint`, `eyebrow`, `caption`, plus
  native `placeholder`/`aria-label`/`alt` and `<Head title>`. **Including the six untranslated
  default literals declared inside component definitions**, which any call-site-only extractor
  misses entirely.
- **`data-no-i18n`** — the opt-out already exists at 183 sites across 55 files and is honored; the
  one known over-broad case (`Components/Electoral/BallotReceipt.vue:72`, whose slot fallback
  *"Receipt"* is genuinely translatable) is emitted as a flag, not silently obeyed.

**Namespaces** = per page-folder, single-level, matching the loader glob `./locales/*/*.json`
exactly (a nested `locales/en/pages/civic.json` would **not** match). Projected key counts from the
measured census: `legislature` 932 · `civic` 441 · `elections` 421 · `operator` 379 · `setup` 379 ·
`organizations` 301 · `executive` 291 · `judiciary` 256 · `dev` 222 · `jurisdictions` 219 ·
`system` 174 · `auth` 56 · plus `components`, `registry` (the 281 unkeyed strings in
`resources/js/registry/surfaces.js` and `journeys.js` — a drift a `.vue`-only sweep never sees),
`server` (the ~455 PHP config + flash strings, `config/cga/surfaces.php` alone holding 280), and
the six existing chrome namespaces. Keys are `<ns>.<file>.<slug>`, deduped on normalized text
(measured duplication is only 1.08×; the top repeats are `Cancel`, `Population`, `Name`).

**The locale registry — killing the PHP↔JS drift.** Five independent locale lists exist today:
`resources/js/i18n/index.js:42-50` (canonical, the only one carrying `dir`),
`SetLocale.php:23`, `MyRecordController.php:44`, `resources/views/app.blade.php:5` (an RTL set
listing `fa`/`he`/`ur`, which exist nowhere else), and a hardcoded `LANGUAGES` array in
`Auth/Register.vue:34-40`. One generator ends this: `scripts/i18n/languages.py` — **a new file, not
an edit to `scripts/etl/languages.py`**, which is a geodata country→official-language ETL feeding
`jurisdictions.official_languages` and is a different artifact with the same name. Registry fields:
`code`, `endonym`, `english_name`, `dir`, `script`, `plural_rules`, `fallback_chain`, `tier`,
`enabled`. It emits `config/locales.php` and the JS registry; `check.mjs` fails if either generated
file is stale.

**115 → 77 is TWO ladders, not one — and the first ladder does not reach 77.**
`scripts/etl/languages.py` contains exactly **115 distinct language codes**, which is where the
charter's "115 registered locales" came from. Normalizing that set to product locale tags:
Norwegian is triple-counted (`no` + `nb` + `nn` → one, **−2**); `la` and `cr` are not product
locales (**−2**); `zh` must split into `zh-Hans`/`zh-Hant` (**+1**, and the external accessibility
doc requires both with distinct font stacks); the 6 non-ISO-639-1 codes (`ber fil pap pau tet tpi`)
are reclassified to their proper tags rather than dropped (**±0**). That gives **115 − 2 − 2 + 1 =
112 registered locales**, not 77.

**77 is a translation-capability count, not a registration count**, and it needs its own explicit
ladder — model coverage (does NLLB-200 carry the pair at usable quality?) plus a speaker floor,
with sole-official-language locales exempt from the floor. Those two numbers were being used
interchangeably; they are different sets, and `registered ⊃ translated ⊃ published`.

**The generator prints the counts; this document does not assert them.** `languages.py` emits the
enumerated list and its count for each tier, and the pin asserts **exact equality against a
committed list** — not `>= 77` — so a membership change is a failing diff rather than a silently
passing inequality. Every figure downstream (units, cost, ETA denominators, the coverage grid)
cites the generator's number with its provenance, so a re-measurement updates one value rather
than five.

**Glossary.** 36 → **38 terms**, adding **Public record** and **Testimony** per the operator's
ruling — the two whose absence was most conspicuous (WF-SYS-03 is the only constitutional
translation mandate and its own subject was untranslated; testimony is a first-class civic artifact
with its own route and federation test). The file is currently **orphaned** — zero importers, zero
tests — so v2 also gives it a consumer and a pin. Schema carries N locales, `do_not_translate`,
part-of-speech, a context note, and a **spoken form** (lane 11 needs pronunciation guidance for ID
tokens read aloud; the written form stays this lane's registry). Enforcement: the term base is
injected into the cached system prefix, and `check.mjs` fails on any term rendered off-base. The
existing `ID_TOKEN` regex (`resources/js/i18n/index.js:57`) already protects `R-`/`WF-`/`F-`/`I-`/
`CLK-` and is **reused, not reinvented**; the build adds the `Art. … §…` citation guard that the
glossary's own `_comment` declares but no code currently enforces.

**Vetted-copy units.** The fleet-wide seat-rule line — *districts elect five to nine
representatives; legislatures scale by cube root and are composed of districts* — is a **pinned
translation unit**: translated once per locale, reviewed, marked `locked`. A UI string, a slide, a
subtitle and a social post can then never disagree about what a district is. Same treatment for
every line lane 15's corrected-wording pass ratifies. Term base and pinned units are posted to the
fleet board when they land, so lanes 9/11/12 consume rather than re-derive them.

**The wrap-up-wave protocol.** This lane never edits `.vue` markup — lane 6 owns it. Extraction is
still useful before any markup change because each page yields two artifacts: the keyed catalog
`resources/js/i18n/locales/en/<ns>.json` (this lane's file, lands immediately) and
`docs/plans/i18n/waves/<page>.md` (a mechanical `literal → $t('key')` mapping — a *document*, not a
patch this lane applies). Lane 6 applies it when that page is declared done. The coverage dashboard
therefore distinguishes **extracted** (the key exists) from **wired** (`check.mjs` finds no
remaining literal in the file); a page is 100% only when both hold.

---

## 7. Storage, versioning, serving

**Two planes, one boundary rule:** if the string lives in the repo it is Plane A; if it lives in the
database it is Plane B. No exceptions — that keeps `git blame` meaningful for UI copy and keeps
user-authored content out of Git.

### Plane A — catalogs

`resources/js/i18n/locales/<code>/<ns>.json`, the exact shape the existing glob already accepts with
**zero loader changes**. `en/*.json` is **generated by the extractor** (the components are the
source of truth); translated catalogs are generated from `translation_memory` at build time.

Per-key metadata (`source_hash`, `status`, `provider`) lives at
**`resources/js/i18n/meta/<code>/<ns>.json` — a sibling tree the loader glob never sees.** This is
deliberate and was a bug in the first draft of this plan: a co-located `locales/<code>/<ns>.meta.json`
*matches* `./locales/*/*.json` and would be merged as a namespace literally called `<ns>.meta`,
shipping every provenance record to every browser and inflating each locale by ~40%. Metadata is
read only by `check.mjs` and the build step. **No new tables on the build-time path**, per the
charter, and a changed source string invalidates its translations by hash mismatch, which
`check.mjs` reports.

**Git ergonomics.** 77 locales × ~20 namespaces ≈ **1,540 files** that a single run rewrites. Only
`en/**` and the meta files are committed and reviewed; translated catalogs are **build artifacts**,
so no human is ever asked to review a 1,540-file diff, and a re-translation is not a repo event.

**Serving — the eager-bundle blocker.** Today `import.meta.glob('./locales/*/*.json',
{ eager: true })` pulls every locale into the main chunk. At 4,650 unique keys × mean 77 chars, one
Latin locale is ≈ 358 KB of text plus ~30 chars/key of JSON key and quoting overhead (~140 KB) ≈
**~500 KB**. Non-Latin is worse, and it is measured rather than assumed: `hi.json` is 6,643 B
against `en.json`'s 4,572 B for *fewer* keys — ~1.57× per key — so Hindi ≈ **785 KB**. Eager × 77
locales ≈ **~45 MB uncompressed** in the main bundle. Not shippable. The fix is mostly inside this
lane's own file — drop `eager: true`, and fix `mergeNamespaces` to seed **every registry code**
rather than the five it hardcodes (`index.js:31`, a bug that would otherwise silently drop locales
6 through 77). Two wiring details decide whether it works at all:

- **Seed an empty message object for every enabled code at `createI18n` time.** `app.js:23-26`
  gates the initial locale on `i18n.global.availableLocales.includes(...)`. Seed only `en` and
  every non-English user silently renders English on first paint regardless of their persisted
  `users.locale` — the exact opposite of the change's purpose, and invisible because
  `missingWarn`/`fallbackWarn` are `false`.
- **Hang the namespace load off `createInertiaApp`'s `resolve` callback**, which is already `async`
  and is awaited before the component is handed to Inertia. `router.on('before')` does **not** await
  a returned promise, so it races the render and every first visit to a namespace paints English
  and then re-renders — a flash on every navigation, in every locale.

Per-page-load cost then becomes one locale × the namespaces that page uses ≈ **20–60 KB gzipped**.

### Plane B — the memory, not the materialization

`translation_memory` is keyed on a **normalized** source hash: numbers, UUIDs, dates and proper
nouns are masked to placeholders before hashing, so *"Bill 12 passed its second reading"* and
*"Bill 47 passed its second reading"* collide on one entry. System-generated public-record prose is
highly templated, so this collapses the corpus by orders of magnitude — it is what makes the
operator's all-registered-locales ruling tractable at all. Naive per-record materialization would be
1M records × 77 locales × ~450 B ≈ **35 GB**; the template-normalized memory for the same corpus is
megabytes, with the per-record string rendered at read time.

### WF-SYS-03 — the only hard constitutional mandate

*"Any statement, bill, vote, or explanation recorded → public, readily available, immutable record
**with translations**"* (`EXPLORE_registry.md:488`). Everything else in Phase N is [POLICY].

Delivery, around the immutability trigger: `PublicRecordService::publish()` writes
`translations = {"<code>": "pending", …}` for all registered locales **at insert time**, which gives
the badge an honest `total` (the read path counts jsonb keys and treats `pending` as not-done —
`PublicRecordsController.php:166-173,195-197`). The actual text and its upgraded quality live in
`translation_memory`, merged by the read path. `done` then rises as the sweep completes — something
a jsonb `UPDATE` could never do. This also formalizes the `machine|reviewed|locked` vocabulary that
currently exists nowhere but two lines of controller code.

**The Register.vue promise — a pilot with a dated fallback.** `Auth/Register.vue:190` and
`Civic/MyRecord.vue:741` ship `hint="Records are translated per your selection."` bound to
`users.languages`, and nothing delivers it. Per the round-1 ruling the copy **stays** and this lane
**pilots** the minimal honest delivery above. The deadline is an event, not a date: **lane 6's
parity wave reaching `Auth/Register`**. If the pilot is proven by then, the copy was always true; if
not, lane 6 softens two lines, reversible in N. The verdict is posted to the board the moment the
pilot settles, so lane 6 never guesses and lane 2's launch checklist is resolved either way. The
pilot is what makes the promise cheap: **$0.023 per record across all 77 locales**, and normalized
memory means most records are cache hits rather than new work.

### Federation

Translations replicate — `FederationSyncService.php:117` (export) and `:514` (import). Resolution
rule: **quality wins, then authoritative-instance wins.** A peer's `reviewed` beats a local
`machine`; two `reviewed` translations resolve to the authoritative instance. Flagged `@operator` as
constitutional-adjacent rather than decided here.

### Immutability collisions, each handled

- `public_records` — side store + publish-time pending map (above).
- `achievements` — append-only via `achievements_block_mutation()` (`pgsql-schema.sql:122-126`),
  titles denormalized at earn time, so a backfill can never touch them: translate the **config
  source** `config/cga/journeys.php` instead of the rows.
- `law_versions` — append-only and monotonic: a translation is keyed to a version hash and never
  mutates history.

### The numbers

Assumptions stated once and used everywhere: Haiku 4.5 at **$1 / $5 per MTok** with the Batch API's
**−50%**; ~5,100 unique source strings ≈ 393k chars ≈ **98k input tokens** at ~4 chars/token; output
≈ **2.0× input, blended** across a mixed-script target set — not the 1.25–1.4× a Latin-only estimate
suggests. The anchor is measured in this repo: `hi.json` is 6,643 B against `en.json`'s 4,572 B for
*fewer* keys (~1.57× per key in **bytes**), and non-Latin scripts tokenize worse than their byte
ratio implies. Indic/Arabic/Thai run nearer 2.5×; Latin nearer 1.25×.

| Scenario | Input | Output | Cost |
|---|---:|---:|---:|
| One language, one pass | 98k | 196k | **$0.57** |
| Full UI → 77 translated locales | 7.5M | 15.1M | **≈ $44** |
| One public record × 77 locales | 5.8k | 11.6k | **$0.032** |

(No 115-locale row: 112 locales are *registered*, ~77 are *translated* — see §6. Display-only
locales cost nothing.)

**Correcting the first draft's framing:** it called the prompt cache the reason those numbers are
small. It is not. Per language the cached prefix costs ~$0.03 against ~$0.29 uncached, so caching
is a **~1.45× lever on total cost** ($0.57 vs $0.83) — worth having, and worth respecting Haiku's
4,096-token floor for, but **output volume is the dominant term at ~86% of the cached total**. Note
also that Batch and prompt caching interact: batch requests are scheduled asynchronously over a
long window, so prefix hits across a submission are not guaranteed — both figures are stated so the
plan is robust to Batch defeating the cache.

The conclusion survives the correction and is strengthened by it: **money is not the constraint on
this lane; human verification is.** Forty-four dollars buys the machine pass for the entire user
interface in seventy-seven languages. What it does not buy is a human who can confirm that the
Arabic word for *quorum* is right — which is why this plan optimizes the verifier queue and the
glossary, not the provider bill.

---

## 8. Concurrency, budget, and postgres posture

- **One limiter**: `HostCapacity::autoscaleWorkers()` — reused, never duplicated. Cloud lane capped
  at 30% of that.
- **The budget rail is enforced in the claim path**, not reported after the fact (§3 step 1).
- **The router is NEVER bound globally over `TranslationProvider::class`** — this is the single
  most dangerous edit available in this lane, and it is ruled out by design. `TranslationGate`
  takes one injected provider and calls `isCloud()` **with no arguments, before it knows anything
  but the room**; `AppServiceProvider:25` binds that interface globally, and the live K-3 path
  (`POST /civic/matrix/translate`) resolves exactly that binding. Rebind it to a tier-routing
  provider and one of two things happens: the router reports `isCloud() = true` conservatively and
  **every private, encrypted and org-private room silently loses translation** — a shipped K-3
  feature, gone; or it reports `false` and the rail **fails open**, putting private text on a cloud
  wire. It cannot report accurately, because `isCloud()` is called before `translate()` and never
  learns the locale or namespace.
  **And the existing pins would not notice either outcome**: `TranslationPrivacyRailTest`
  constructs `TranslationGate` directly with Mockery doubles and never resolves the container.
  So the plan's "the four constitutional pins stay green" is true and *not a safety argument*.
  Instead: the router is a **contextual binding injected only into the sweep engine** (the
  `MediaScanProvider` pattern applied per-consumer), the Matrix gate keeps the local provider, and
  routing is decided by the caller from `(tier, namespace, is_private)` **before** a provider is
  handed to a gate — never inside an `isCloud()` the gate calls first.
  New pin: resolve the binding `MatrixTranslationController` actually receives and assert
  `isCloud() === false`.
- Default posture stays local NLLB; `config/matrix.php:129`'s currently-dead `provider` key becomes
  the sweep-side factory's input, so the cloud tier is one env var away when local capacity runs
  out — the operator's "local for now" posture, without a code change standing between him and the
  switch.
- **Cross-engine core contention is a real constraint, not a footnote.** `HostCapacity` states the
  settled ruling verbatim — *one* concurrency limiter, cores−2. The local NLLB lane contends for
  exactly the resource autoscale's limiter governs, so a live districting run (10 workers) plus a
  translation run on a 12-core box is 18 CPU-bound processes against 10 reserved cores, and the
  geodata engine would add a third pool. Rule: **local-tier translation claims are refused while
  any autoscale or geodata run is non-terminal**; the cloud tier, being network-bound, keeps
  flowing. No second dial is minted.
- **THE ETL RULE** applies to every bulk memory write: bounded committed chunks with per-chunk
  progress, resumable at any boundary, never one opaque multi-hour transaction.
  `SET max_parallel_workers_per_gather=0` on planet-wide passes; `VACUUM ANALYZE` churned tables at
  phase boundaries, transaction-guarded.

---

## 9. Tests

- **`TranslationPullEngineTest`** (live-PG, synthetic items, **no real MT executed**) — `driveRun()`
  alternates `(new TranslationWorkerJob($runId))->handle()` with
  `Artisan::call('translation:pump')` until `done`, the `AutoscalePinTest:70-80` idiom (no queue, no
  Horizon). Asserts: claim order is cheapest-first; the glossary barrier holds (no prose claim
  before its locale's seed is `done`); the freeze gate holds; stale reclaim + redo never
  double-writes; **halt parks the run and a worker claims nothing**; breaker pause claims nothing;
  **the budget cap stops claiming**; the requeue recipe resets to head; **revert deletes `machine`
  and preserves `reviewed`/`locked`**; audit cardinality (one append per batch, one per run).
- **`TranslationClaimRouterTest`** — synthetic rows only, the `HeavyLaneClaimTest` shape: cloud-lane
  cap arithmetic, a full cloud pool routes the next claim to local work, the drain rule lifts the
  cap.
- **`TranslationPrivacyRailTest`** — **extended, not replaced**: the existing four constitutional
  pins stay green, plus the new DB `CHECK` rejects a cloud-provider row marked private, plus — the
  gap the existing pins cannot see — **a container pin**: resolve the `TranslationProvider` that
  `MatrixTranslationController` actually receives and assert `isCloud() === false`, so no future
  global rebinding can disarm the K-3 rail while the Mockery-based pins stay green.
- **`I18nMessageFormatTest`** — an Arabic 6-form plural renders the correct branch at
  n = 0, 1, 2, 3, 11, 100 (proving `pluralRules` are registered), and a source string containing a
  literal `|`, `{` and `@` survives extract → emit → compile without changing meaning.
- **`I18nCatalogParityTest`** — the day-one case is the **live 8-key drift**: `nav.commons`,
  `nav.federation`, `nav.halls`, `nav.liveHalls`, `nav.liveSquare`, `nav.operatorOps`,
  `nav.publicSquare`, `nav.rooms` — missing from all four non-English catalogs and silent today
  because `missingWarn:false`. Red now, green after step 2.
- **`check.mjs --ci`** exits non-zero **standalone**, so it gates from day one with no CI. Per the
  round-1 ruling, standing CI up is not this lane's deliverable and is not 40-day-critical; lane 2
  carries the one-line decision and the checker drops in without a rewrite.
- `DistrictingDoctrineTest`, `AutoscalePinTest` and the full suite stay green — nothing here touches
  districting, the hardened layer, or any PROTECTED file.

---

## 10. Build order (each step lands green before the next)

1. **`extract.mjs` + the audit** → `docs/plans/i18n/EXTRACTION_AUDIT.md`, real per-page counts,
   reproducible by one command.
2. **`check.mjs` + `I18nCatalogParityTest`** — the 8-key drift goes red, then green.
3. **`languages.py` registry** → `config/locales.php` + the generated JS registry; the five drifting
   lists collapse to one. A staleness check fails on a hand-edit.
4. **Glossary v2** — 38 terms, N-locale schema, spoken forms, pinned vetted-copy units, enforcement
   in `check.mjs`. An off-base term fails the gate. Term base posted to the board for lanes 9/11/12.
5. **Migration + models + `TranslationClaims` + `translation:pump` + both pin tests** — a synthetic
   run drives to `done` with zero MT calls.
6. **`translate_catalog.py` + provider router + QA suite** — one namespace, one locale, end to end,
   with real token counts.
7. **Progress endpoint + the dashboard page** — on **`AppShellV2`** per the shells ruling, reusing
   `Components/Setup/StackedProgressBars.vue` and the autoscale worker strip unchanged. Live bars
   during a run, with an AFTER-screenshot on `localhost:8080` per the standing proof rule.
8. **Lazy serving** — the `index.js` glob change + the `mergeNamespaces` seed fix. Bundle size
   before/after: ~45 MB eager → 20–60 KB gzipped per page load.
9. **The three-language pilot**, measured cost and wall-clock per language, extrapolated to 77.
   Locales chosen for what they *prove*, not for speaker count: **fr** (Latin, close NLLB pair — the
   clean baseline), **ar** (RTL end to end, and the only `dir` the registry exercises today), **bn**
   (non-Latin script, complex plural rules, a genuine NLLB long-tail case). Frozen keys excluded.
10. **WF-SYS-03 pilot** — publish-time pending map, memory-merged read path, the record sweep. A
    record published in the pilot locales shows a rising `done/total` badge, and the verdict reaches
    the board **before lane 6's wave reaches `Auth/Register`**.

---

## 11. Non-goals / guardrails

- **No edits to lane 6's `.vue` files, ever.** This lane ships catalogs and wave documents; the one
  new file it authors is its own dashboard page, on the V2 shell.
- **No machine pass over frozen copy** — the faction-language freeze is enforced in the claim path
  and again at publish, so corrected-pending wording cannot reach 77 languages by accident.
- **No new MT abstraction** — build into K-3's `TranslationProvider`/`TranslationGate`; its four
  constitutional pins stay green. `POST /civic/matrix/translate` gains its first caller rather than
  a competitor.
- **Accessibility is Phase N but not this lane** (folded into the UI lane 2026-07-24).
- **No change to the hardened layer, PROTECTED files, the hash-chained audit log, or ballot
  secrecy.** Translation is presentation-only: **no locale ever alters a hardened computation.**
- Private content is local-provider-only, **triple-railed**: the gate, the provider's `isCloud()`
  bit, and the database CHECK — plus the container pin that keeps the first rail armed.
- **No CHECK constraint on `public_records.translations`.** It is a federation-replicated column
  (`FederationSyncService.php:117,514` export and import it verbatim), and the current reader
  deliberately accepts two shapes — a scalar quality string *or* an object with a `quality` key
  (`PublicRecordsController.php:171`). A constraint tight enough to be useful would reject payloads
  the reader is written to consume and would abort an inbound sync batch from a peer on older code
  — and because `publish()` runs inside `ConstitutionalEngine::file()`'s transaction, it could
  abort a lawful local publication too. **Validate on local publish; normalize, never reject, on
  import.**
- Standing CI up is lane 2's call; migrating any of the 19 old-shell pages is lane 6's punchlist,
  pending the operator.

---

## 12. Open items reserved to the operator

1. **Minting `F-SYS-LOC-PUBLISH` and `F-SYS-TR-REVIEW`** in `app/Domain/Forms/FormRegistry.php`.
   No `F-SYS-*` ID exists today, and the registry's own header calls itself *"a constitutional
   artifact versioned with code — never a DB table"*. Minting form IDs is his word, not an
   engineering dial.
2. **A Translation Verifier role.** No such role exists: `config/mesh_roles.php` holds four operator
   roles and `RoleService` derives R-01…R-30. The human loop this plan depends on needs a home —
   a fifth mesh role, a new derived civic role, or a capability on an existing one.
3. **Federation conflict resolution for translations** — quality-wins-then-authoritative is proposed
   above; it is constitutional-adjacent and worth his ruling rather than this lane's assumption.
4. **Ownership extension.** This lane owns `resources/js/i18n/**` and `scripts/i18n/**`. The engine
   necessarily also touches `database/migrations/` (one additive migration),
   `app/Services/Translation/`, `app/Jobs/`, `app/Console/Commands/`, one line in
   `routes/console.php`, the generated `config/locales.php`, and — for WF-SYS-03 only —
   `PublicRecordService::publish()` and `PublicRecordsController`'s read path. Note that
   `routes/console.php` is now a **three-lane shared write** (autoscale, geodata, translation all
   schedule `everyMinute` commands); a collision protocol for it is worth stating fleet-wide.
5. **May private source text be retained in the translation memory at all?** The memory keeps
   `source_text` so the verifier queue can show a reviewer what they are correcting. For T3
   content that means private prose sits in a table verifiers can page through, outliving deletion
   of the row it came from. The privacy rail keeps it off cloud wires; it does not make it
   *forgettable*. Two honest options: keep it (and scope who may read the queue), or have private
   units bypass the memory entirely — translated per-row, never pooled, at the cost of losing reuse
   on exactly the highest-volume corpus. This is a privacy posture, not an engineering dial.

---

## 13. Revision note — adversarial review pass (2026-07-25)

This document was re-verified after publication by an independent adversarial pass over three
lenses (constitutional rails, arithmetic, engine mechanism). Twelve findings were adopted; the
substantive corrections, recorded so the reasoning is not lost:

| # | What was wrong | Where |
|---|---|---|
| 1 | Binding the tier router globally over `TranslationProvider::class` would break the live K-3 private-room path — and the existing pins could not detect it | §8, §9 |
| 2 | `translation_memory`'s uniqueness key omitted `is_private`, letting a cloud-translated public string be matched for private content | §2 |
| 3 | The message format is **vue-i18n's, not ICU**; no `pluralRules` are registered, and `\|`/`{`/`@` are reserved characters an extractor must escape | §6 |
| 4 | **The 115 → 77 arithmetic did not work** — the stated exclusions give 112. Registration and translation-capability are two different ladders | §6 |
| 5 | **Metadata sidecars sat inside the loader's glob** — `<ns>.meta.json` would have shipped to browsers as a namespace | §7 |
| 6 | Phase advance could fire mid-enumeration, publishing a locale at partial coverage | §1 |
| 7 | Completion defined as a done-count could strand a 99.9%-finished run forever | §1 |
| 8 | Spend reconciled from surviving rows silently erases real money on any retry | §5 |
| 9 | Lazy loading would break `app.js`'s `availableLocales` guard and race Inertia's render | §7 |
| 10 | Output expansion at 1.4× was optimistic (2.0× blended); the prompt cache is a ~1.45× lever, not the dominant one — output volume is | §7 |
| 11 | Local-tier workers contend with autoscale for the same cores under a one-limiter ruling | §8 |
| 12 | A CHECK on the federation-replicated `translations` column would abort peer syncs | §11 |

Findings 4 and 5 were errors introduced by this lane. Both are corrected above rather than
quietly amended. One finding was **declined**: the review flagged the WF-SYS-03 badge as unable to
express a shortfall — its recommended fix (seal the owed locale set at INSERT, let delivery records
carry only delivery) is already this plan's design, arrived at independently from the immutability
trigger in §0.
