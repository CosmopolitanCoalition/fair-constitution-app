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
  `UNIQUE(source_hash, locale)`; index `(locale, status)`.
  **The privacy rail as a database CHECK**, per the charter:
  `CHECK (NOT (is_private AND provider LIKE 'cloud-%'))` — the rail lives in the schema, not only
  in `TranslationGate`, so no code path can route private text to a cloud provider.

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
payload — comfortably inside Haiku's 200K window and NLLB's practical batch — ~20–40 s per NLLB
batch, and a failed batch costs at most 40 strings of redone work.

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
  (3) **budget tick** — roll `metrics->tokens` into `budget_tokens_spent`; at cap, park the run ·
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
- **Interpolation-mixed nodes** — 645 — become ICU messages with named placeholders.
- **Pluralization** — ~62 hand-rolled ternaries today and *no* `$tc` anywhere; the floor is higher
  than the count, because `{{ n }} clocks` with no singular branch is grep-invisible and currently
  renders "1 clocks". These become ICU plurals with the registry's per-locale plural rules.
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

**115 → 77, with the arithmetic.** `scripts/etl/languages.py` contains exactly **115 distinct
language codes** — which is where the charter's "115 registered locales" number came from.
Reconciling to the charter's other number: 6 codes are not ISO 639-1 (`ber fil pap pau tet tpi`)
and are reclassified rather than silently dropped; Norwegian is triple-counted (`no` + `nb` + `nn`
→ one product locale, −2); `la` and `cr` are not product locales (−2); `zh` must split into
`zh-Hans` and `zh-Hant` (+1, and the external accessibility doc requires both with distinct font
stacks). The remaining product set lands at **~77 locales** — the two charter numbers finally
connected. The build pins the exact list and this derivation so neither number floats again.

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
Per-key metadata (`source_hash`, `status`, `provider`) lives in a sibling
`locales/<code>/<ns>.meta.json` — **no new tables on the build-time path**, per the charter — and a
changed source string invalidates its translations by hash mismatch, which `check.mjs` reports.

**Git ergonomics.** 77 locales × ~20 namespaces ≈ **1,540 files** that a single run rewrites. Only
`en/**` and the meta files are committed and reviewed; translated catalogs are **build artifacts**,
so no human is ever asked to review a 1,540-file diff, and a re-translation is not a repo event.

**Serving — the eager-bundle blocker.** Today `import.meta.glob('./locales/*/*.json',
{ eager: true })` pulls every locale into the main chunk. At 4,650 unique keys × mean 77 chars, one
Latin locale is ≈ 358 KB of text plus ~30 chars/key of JSON key and quoting overhead (~140 KB) ≈
**~500 KB**. Non-Latin is worse, and it is measured rather than assumed: `hi.json` is 6,643 B
against `en.json`'s 4,572 B for *fewer* keys — ~1.57× per key — so Hindi ≈ **785 KB**. Eager × 77
locales ≈ **~45 MB uncompressed** in the main bundle. Not shippable. The fix is entirely inside this
lane's own file: drop `eager: true`, fix `mergeNamespaces` to seed **every registry code** rather
than the five it hardcodes (`index.js:31` — a known bug that would silently drop locales 6–77), and
`await` the active locale's namespace chunks at boot. Per-page-load cost becomes one locale × the
namespaces that page uses ≈ **20–60 KB gzipped**.

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

Assumptions stated: Haiku 4.5 at **$1 / $5 per MTok** with the Batch API's **−50%**; ~5,100 unique
source strings ≈ 393k chars ≈ **98k input tokens** at ~4 chars/token; output ≈ **1.4×** input for a
mixed-script target set (the measured `hi.json` expansion is the anchor).

| Scenario | Input | Output | Cost |
|---|---:|---:|---:|
| One language, one pass | 98k | 137k | **$0.42** |
| Full UI → 77 languages | 7.5M | 10.6M | **≈ $32** |
| Full UI → 115 locales | 11.3M | 15.8M | **≈ $48** |
| One public record × 77 locales | 5.8k | 8.1k | **$0.023** |

The cached prefix is why those numbers are small: a ~4.5k-token glossary+style prefix **uncached**
costs $0.58/language — more than the corpus — and **cached** (1.25× write once, 0.1× per read)
costs $0.06. Haiku's 4,096-token cache floor means the prefix must be deliberately large enough to
qualify. **Money is not the constraint here; human verification is** — which is why the verifier
queue, not the provider bill, is the thing this plan optimizes.

---

## 8. Concurrency, budget, and postgres posture

- **One limiter**: `HostCapacity::autoscaleWorkers()` — reused, never duplicated. Cloud lane capped
  at 30% of that.
- **The budget rail is enforced in the claim path**, not reported after the fact (§3 step 1).
- Provider binding stays `LocalStubTranslationProvider` → NLLB by default; the currently-dead
  `config/matrix.php:129` `provider` key gets wired to a factory, so the cloud tier is one env var
  away when local capacity runs out — the operator's "local for now" posture, without a code change
  standing between him and the switch.
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
  pins stay green, plus the new DB `CHECK` rejects a cloud-provider row marked private.
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
  bit, and the database CHECK.
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
   `PublicRecordService::publish()` and `PublicRecordsController`'s read path.
