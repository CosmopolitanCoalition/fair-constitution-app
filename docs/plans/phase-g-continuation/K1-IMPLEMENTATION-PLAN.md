# Phase K-1 — The Civic Record Plane — Implementation Plan

Builder-executable, sequential. Every choice is grounded in the extracted patterns and the in-tree
code I verified (engine, validator, registry, `PublicRecordService`, `RoleService`, `PetitionController`,
`PhaseEDemoCommand`, the `peer_upgrade` migration, `nav.js`, `surfaces.php`). Build the slices in order;
each ends green before the next begins.

---

## 0. The K-1 → K-2 → K-3 build sequence

| Sub-phase | One-line scope | Rig? |
|---|---|---|
| **K-1 (THIS PLAN)** | The CIVIC RECORD plane: `social_*` tables, public square + halls, F-SOC-001 (open thread / post) + F-SOC-002 (file testimony → `PublicRecordService::publish`, back-pointer `social_threads.published_record_id`), the auto-bind reconciler (one subforum per live governance object, idempotent), the four moderation carve-outs, the `FORBIDDEN_SUBJECT_TYPES` privacy rail, controllers/routes/Vue pages, `social:demo`. **Dev-stack only, no Matrix, no rig.** | No |
| **K-2** | `education_tracks/modules/questions/progress` (server-graded; `correct_keys` never serialized to client; `education_progress` never federates) + Learn Area + `achievements` (append-only, partial-unique idempotent award) + `AchievementService` (zero new F-forms). | No |
| **K-3** | Matrix single-instance per `K3-MATRIX-RESEARCH-AND-DESIGN.md`: Dendrite homeserver (`fc_matrix`) + CGA appservice + v12 immutable-creator power-clamp (appservice sole PL≥50 in public rooms) + local OIDC IdP + the legitimacy-gated moderation flip. Rig-gated for the cross-instance half. | Yes (cross-instance half) |

K-1 is the constitutionally-compelled append-only record (Art. II §2) and ships independently of the
heavier Matrix work. The `social_*` plane K-1 builds is **plane A** (the civic record); K-3's Matrix is
**plane B** (live commons), bridged through F-SOC-002 testimony filing — never merged.

**Phase letter:** the roadmap §4 names this **Phase K**. `HandleInertiaRequests.php:73` uses single
letters `['A','B','C','D','E','F']`. Put `phase: 'K'` on the new nav items and append `'K'` to
`phasesLive` as the final wiring step of K-1. *(Open decision — confirm the letter with the operator
before flipping; MEMORY warns explorations self-assign colliding letters. Do not invent a new letter.)*

---

## 1. K-1 in committable slices

Each slice is independently committable, ends with green live-pg pins, and never edits a protected
migration.

### Slice K1-A — Schema (the `social_*` tables)
- **Files (new migrations, date prefix strictly after `2026_10_09_000002`):**
  - `database/migrations/2026_10_10_000001_create_social_spaces_subforums_tables.php` — `social_profiles`, `social_spaces`, `social_subforums`
  - `database/migrations/2026_10_10_000002_create_social_threads_posts_reactions_tables.php` — `social_threads`, `social_posts`, `social_reactions`
  - `database/migrations/2026_10_10_000003_create_social_follows_memberships_tables.php` — `social_follows`, `social_memberships`
- **Models (new):** `app/Models/SocialProfile.php`, `SocialSpace.php`, `SocialSubforum.php`, `SocialThread.php`, `SocialPost.php`, `SocialReaction.php`, `SocialFollow.php`, `SocialMembership.php` — all `extends Model { use HasUuids, SoftDeletes; }`, enum strings as `public const`, `'id'` first in `$fillable`, typed `BelongsTo`/`HasMany`. (Column-level spec in §2.)
- **Build:** the three local-only tables (`social_reactions`, `social_follows`, `social_memberships`) get identical UUID-PK / `timestampsTz` / `softDeletesTz` shape; their LOCAL-ONLY guarantee is enforced in the *service layer* (§6), not the migration. Partial-unique on `social_subforums (governing_object_type, governing_object_id) WHERE deleted_at IS NULL` is the auto-bind reconciler's idempotency key.
- **Pin (`tests/Constitutional/SocialSchemaTest.php`, live-pg):** schema asserts — every `social_*` table has `id` (uuid) / `created_at`/`updated_at`/`deleted_at` (timestamptz); the subforum partial-unique exists; the `social_subforums_object_unique` index is `WHERE deleted_at IS NULL`; CHECK enums match the model consts byte-for-byte. Mirror the `information_schema` posture of `CgcIpPublicDomainTest.php:45-102`.

### Slice K1-B — F-SOC-001 (open thread / post in a space), engine-routed, R-03-gated
- **Files:**
  - `app/Domain/Forms/Handlers/SocialThreadPost.php` — new `FormHandler` (§3).
  - `app/Services/Social/SocialSpaceService.php` — new service the handler delegates to (creates space-on-demand for the public square, opens a thread, appends a post; pattern: `PetitionService` delegated-to by `PetitionCreation.php:58`).
  - `app/Domain/Forms/FormRegistry.php` — register `F-SOC-001` in `FORMS` (after the F-ADV block, ~line 174) with `'roles' => ['R-03']`, and in `HANDLERS` (after the Phase F block, ~line 383). Bump the `'103 forms'` docblock count (FormRegistry.php:8,13) — descriptive, not load-bearing.
- **Pin (`tests/Constitutional/PublicSquareTest.php`, live-pg, connection `pgsql_k1_social`):**
  - a resident (active `residency_confirmations`) files `F-SOC-001` → a `social_thread` + `social_post` row exists; recorded array carries `thread_id`/`post_id`.
  - a non-resident (`R-01` only) is **rejected** with citation `'CGA Roles & Forms Chart'` (engine `authorize()` throws this; ConstitutionalEngine.php:200-207) — residency is the only gate, never karma/age.
  - the public square page never 403s the un-associated viewer (controller test, Slice K1-E).

### Slice K1-C — F-SOC-002 (file testimony in a hall → public_records bridge)
- **Files:**
  - `app/Domain/Forms/Handlers/SocialTestimonyFiling.php` — new `FormHandler`, injects `PublicRecordService`; copies the hall post into `public_records` (kind `'testimony'`) and stamps `social_threads.published_record_id` (§3).
  - `app/Domain/Forms/FormRegistry.php` — register `F-SOC-002` (`'roles' => ['R-03']`) + handler mapping.
- **Pin (`tests/Constitutional/HallsTestimonyTest.php`, live-pg):**
  - file `F-SOC-002` from a resident → a `public_records` row with `kind='testimony'`, `via_form='F-SOC-002'`, `audit_seq` non-null (sealed — `PublicRecordService.php:113`); the `social_threads.published_record_id` back-pointer equals the returned `->id` (the uuid, **not** `->seq`).
  - **two chain rows** result (the `records/published` entry from `publish()` + the engine's own `F-SOC-002` success entry) — assert both, matching the `PublicRecordStatement` precedent.
  - the recorded payload carries `actor_display` (pseudonym), never `name`/email.

### Slice K1-D — Auto-bind reconciler + `EvaluateSocialStructureJob`
- **Files:**
  - `app/Services/Social/SubforumReconciler.php` — idempotent: one subforum per *live* governance object (bills, referendum questions, petitions, committee meetings, candidacies). Uses `firstOrCreate`/upsert keyed on `(governing_object_type, governing_object_id)`; archives subforums whose object closed.
  - `app/Jobs/EvaluateSocialStructureJob.php` — the queued sweep that runs the reconciler per civically-active jurisdiction (§4).
- **Pin (`tests/Constitutional/SubforumReconcilerTest.php`, live-pg):** running the reconciler twice over the same governance objects yields **exactly one** live subforum per object (idempotency); the partial-unique index never throws; a closed object's subforum flips to `status='archived'`.

### Slice K1-E — Moderation carve-outs (validator rules) + FORBIDDEN_SUBJECT_TYPES rail
- **Files:**
  - `app/Services/ConstitutionalValidator.php` — add F-SOC arms to the `check()` `match` (line 276-299) delegating to new private check methods + pure-asserts (mirror `assertReferendumActModifiable`, ConstitutionalValidator.php:827). The public-square **no-censor default** lives here (§5).
  - `app/Services/PublicRecordService.php:34-39` — extend `FORBIDDEN_SUBJECT_TYPES` with `'social_reaction'`, `'social_follow'`, `'social_membership'` (§6).
- **Pin (`tests/Constitutional/SocialModerationCarveoutsTest.php`, live-pg):**
  - a removal with no valid carve-out is **rejected** with `'Art. I'` citation (public square cannot be censored).
  - M-1: only a derived R-19/R-20 judge may invoke a judicial-order removal; a non-office actor is rejected.
  - M-4: anti-spam is system-only (null actor); a human invoking it is rejected.
  - **privacy pin (source-scan + const-assert):** `social_reaction/follow/membership` are in `FORBIDDEN_SUBJECT_TYPES`; no code path passes those subject_types to `publish()` (mirror the `ElectionClockTest.php:92-105` source-scan rail).
  - **append-only pin:** halls testimony lands in `public_records` (immutable); no DELETE route exists for hall posts.

### Slice K1-F — Controllers, routes, Vue pages, surfaces, nav, demo, phasesLive
- **Files:**
  - `app/Http/Controllers/Civic/PublicSquareController.php`, `HallsController.php` — engine-routed, never mutate the DB directly (§7).
  - `routes/web.php` — routes inside an existing `Route::middleware('auth')` group (§7).
  - `config/cga/surfaces.php` — `'civic/public-square'`, `'civic/halls'` (+ detail) entries (§7). **Register F-SOC forms in FormRegistry first** or `SurfaceMeta::for()` / `SurfaceMeta::form()` 500s.
  - `resources/js/Pages/Civic/PublicSquare.vue`, `Halls.vue`, `ThreadDetail.vue`, `HallDetail.vue` — copy `Petitions.vue` structurally; **zero new CSS** (§7).
  - `resources/js/Navigation/nav.js` + `resources/js/i18n/{en,es,ar,hi,zh-Hans}.json` — new `visibility:'all'` public-square section, `phase:'K'` (§7).
  - `app/Console/Commands/SocialDemoCommand.php` — `social:demo {--fresh}` (§8).
  - `app/Http/Middleware/HandleInertiaRequests.php:73` — append `'K'` to `phasesLive` (final step).
  - `tests/Constitutional/FuturePhasePlaceholdersTest.php:120` — add the new K-1 test filenames to the `assertFileExists` loop so a deletion surfaces.

---

## 2. Column-level schema (reconstructed, additive only)

**Conventions for every table** (all): UUID PK `id` first, `DB::statement('ALTER TABLE <t> ALTER COLUMN id SET DEFAULT gen_random_uuid()')` safety net, all enums = `$table->string('col', N)` + raw `ADD CONSTRAINT <table>_<col>_check CHECK (col IN (...))`, `$table->timestampsTz()` then `$table->softDeletesTz()` (this order), bare-uuid + `index()` for soft refs (governance-object / actor user ids), `->foreign()->onDelete('cascade')` for intra-K-1 structural parent links.

### `social_profiles` (one per user; pseudonymity)
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid, bare + `index` | soft ref to `users.id` (federation-safe convention) |
| `handle` | string(64) nullable | unique pseudonymous handle |
| `display_name` | string(120) nullable | pseudonym — **never** `name`/email |
| `bio` | text nullable | |
| `visibility` | string(12) default `'public'` | CHECK `('public','jurisdiction','private')` — **a profile choice NEVER gates a right** |
| timestampsTz + softDeletesTz | | |
- Partial-unique: `social_profiles_user_unique ON (user_id) WHERE deleted_at IS NULL`; `social_profiles_handle_unique ON (lower(handle)) WHERE handle IS NOT NULL AND deleted_at IS NULL`.

### `social_spaces` (public_square | halls)
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `jurisdiction_id` | uuid, bare + `index` | the jurisdiction this space belongs to |
| `space_type` | string(16) | CHECK `('public_square','halls')` |
| `title` | string(200) | |
| `slug` | string(120) nullable | |
| `status` | string(12) default `'open'` | CHECK `('open','archived')` — **never `'locked'` for public_square** (uncensorable); halls are open-and-append-only |
| `is_private` | boolean default false | org/user self-moderated space (Art. I private half) |
| `owner_org_id` | uuid nullable, bare + `index` | set for private org spaces (R-23 owner) |
| timestampsTz + softDeletesTz | | |
- Partial-unique: `social_spaces_jur_type_unique ON (jurisdiction_id, space_type) WHERE is_private = false AND deleted_at IS NULL` (one public square + one halls per jurisdiction; private spaces unconstrained).

### `social_subforums` (auto-bound to governance objects, idempotent)
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `space_id` | uuid | FK → `social_spaces` `onDelete('cascade')` |
| `governing_object_type` | string(40) nullable | `'bill'`/`'referendum_question'`/`'petition'`/`'committee_meeting'`/`'candidacy'` — null for the bare square subforum |
| `governing_object_id` | uuid nullable, bare + `index` | soft ref to the object (cross-table) |
| `title` | string(200) | |
| `status` | string(12) default `'open'` | CHECK `('open','archived')` |
| timestampsTz + softDeletesTz | | |
- **Partial-unique (the reconciler invariant):** `social_subforums_object_unique ON (governing_object_type, governing_object_id) WHERE governing_object_type IS NOT NULL AND deleted_at IS NULL`. Copy the `peer_upgrade_consents_operator_unique` shape (`2026_10_08_000001:135-138`).

### `social_threads`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `subforum_id` | uuid | FK → `social_subforums` `onDelete('cascade')` |
| `author_user_id` | uuid, bare + `index` | soft ref |
| `author_display` | string(120) | pseudonym snapshot at creation |
| `title` | string(300) | |
| `status` | string(12) default `'open'` | CHECK `('open','archived')` — **no `'removed'`** (Art. I) |
| `published_record_id` | uuid nullable + `index` | **THE back-pointer** — points at `public_records.id` (cross-instance uuid, NOT `seq`); set by F-SOC-002 |
| timestampsTz + softDeletesTz | | |

### `social_posts`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `thread_id` | uuid | FK → `social_threads` `onDelete('cascade')` |
| `author_user_id` | uuid, bare + `index` | soft ref |
| `author_display` | string(120) | pseudonym snapshot |
| `body` | text | the post text |
| `is_official` | boolean default false | a verified seat-holder speaking in office (validated against live roles, never stored authority) |
| `acting_seat` | string(40) nullable | CHECK `('legislature_member','committee_seat','exec_seat','judicial_seat')` when `is_official` |
| timestampsTz + softDeletesTz | | |
- **No `'removed'` status.** Public-square posts are uncensorable; the only removals are the four carve-outs (§5), and an M-1/M-2 removal is itself logged to `public_records`.

### `social_reactions` — **LOCAL-ONLY, never federates**
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `post_id` | uuid | FK → `social_posts` `onDelete('cascade')` |
| `user_id` | uuid, bare + `index` | |
| `kind` | string(16) | CHECK `('up','heart','insightful','flag')` — `'flag'` is a *behavioral* anti-spam signal (M-4), never a viewpoint takedown |
| timestampsTz + softDeletesTz | | |
- Partial-unique: `social_reactions_unique ON (post_id, user_id, kind) WHERE deleted_at IS NULL`.

### `social_follows` — **LOCAL-ONLY, never federates**
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `follower_user_id` | uuid, bare + `index` | |
| `target_type` | string(20) | CHECK `('user','space','subforum')` |
| `target_id` | uuid, bare + `index` | |
| timestampsTz + softDeletesTz | | |
- Partial-unique: `social_follows_unique ON (follower_user_id, target_type, target_id) WHERE deleted_at IS NULL`.

### `social_memberships` — **LOCAL-ONLY, never federates**
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `space_id` | uuid | FK → `social_spaces` `onDelete('cascade')` |
| `user_id` | uuid, bare + `index` | |
| `role` | string(16) default `'member'` | CHECK `('member','owner')` — **owner only on PRIVATE spaces**; this is the in-handler ownership check for private self-moderation (§5), NOT a derived office role and NEVER a public-square "moderator" bit |
| `block_user_id` | uuid nullable, bare + `index` | M-3 per-user block — **client-side curation, never federates/audits** |
| timestampsTz + softDeletesTz | | |

**Federation note:** there is no per-row "do-not-federate" flag in the codebase — `FederationSyncService::buildAuditTail` ships only `public_records WHERE source_server_id IS NULL` (FederationSyncService.php:94). The three local-only tables stay private simply by **never being published**; the `FORBIDDEN_SUBJECT_TYPES` extension (§6) is the belt-and-suspenders tripwire, not the mechanism.

---

## 3. Handler designs + registration

### F-SOC-001 — `SocialThreadPost` (open thread / post, R-03-gated)
Copy `PetitionCreation.php` (resident-gated, service-delegating).
```php
namespace App\Domain\Forms\Handlers;
class SocialThreadPost implements FormHandler {
    public function __construct(private readonly SocialSpaceService $spaces) {}
    public function module(): string { return 'social'; }          // new free-string bucket
    public function event(): string  { return 'post.created'; }     // stable; '.rejected' auto-appended
    public function requiredRoles(): array { return ['R-03']; }      // residency is the ONLY gate (Art. I)
    public function systemOnly(): bool { return false; }
    public function handle(?User $actor, array $payload): array {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A square post is authored by a resident — system filing is not defined.', 'Art. I');
        }
        // delegate: resolve/create the space (square or hall), open-or-find the thread,
        // append the post; snapshot author_display = $actor->display_name ?: $actor->name.
        $result = $this->spaces->openThreadOrPost($actor, $payload);
        return [
            'thread_id'       => (string) $result['thread']->id,
            'post_id'         => (string) $result['post']->id,
            'subforum_id'     => (string) $result['subforum']->id,
            'jurisdiction_id' => (string) $payload['jurisdiction_id'],   // audit jurisdiction-scope
            'author_display'  => $result['post']->author_display,        // pseudonym, never name/email
        ];
    }
}
```
- `module()='social'`, `event()` one of `'thread.opened'` / `'post.created'` — stable verbs, never change post-ship.
- Guard `$actor === null` explicitly: `requiredRoles()=['R-03']` is *bypassed* for a null actor (ConstitutionalEngine.php:193), so a square post that must always have a resident throws inside `handle()` (PetitionCreation.php:51-56 precedent).
- The returned array is hashed verbatim on success (engine strips SENSITIVE_KEYS only on *rejection*) — **omit any PII**; use `author_display` only.

### F-SOC-002 — `SocialTestimonyFiling` (file testimony → public_records, R-03-gated)
Copy `PublicRecordStatement.php:47-84`.
```php
class SocialTestimonyFiling implements FormHandler {
    public function __construct(private readonly PublicRecordService $records) {}
    public function module(): string { return 'records'; }   // it publishes to the register
    public function event(): string  { return 'testimony.filed'; }
    public function requiredRoles(): array { return ['R-03']; }
    public function systemOnly(): bool { return false; }
    public function handle(?User $actor, array $payload): array {
        if ($actor === null) {
            throw new ConstitutionalViolation('Testimony is filed by a resident.', 'Art. I');
        }
        $thread = SocialThread::query()->findOrFail($payload['thread_id']);
        $post   = SocialPost::query()->findOrFail($payload['post_id']);   // the hall post being sealed
        // ... resolve $subforum -> $space -> jurisdiction_id, and the bound governance object
        $record = $this->records->publish(
            kind: 'testimony',                                            // ALREADY in PublicRecord::KINDS — no migration
            title: sprintf('Testimony — %s', $space->title),
            body:  $post->body,
            attrs: [
                'actor_user_id'   => (string) $actor->getKey(),
                'actor_display'   => $actor->display_name ?: $actor->name, // pseudonymity
                'jurisdiction_id' => (string) $jurisdictionId,
                'legislature_id'  => $legislatureId,                       // nullable
                'via_form'        => 'F-SOC-002',
                'subject_type'    => $subforum->governing_object_type,     // 'bills'|'referendums'|...
                'subject_id'      => $subforum->governing_object_id,
            ],
        );
        $thread->update(['published_record_id' => $record->id]);          // THE back-pointer (uuid, not seq)
        return [
            'record_seq'          => (int) $record->seq,
            'record_id'           => (string) $record->id,
            'thread_id'           => (string) $thread->id,
            'published_record_id' => (string) $record->id,
            'jurisdiction_id'     => (string) $jurisdictionId,
        ];
    }
}
```
- **Two chain rows** result (the `records/published` entry from `publish()` + the engine's own `F-SOC-002` success entry) — correct, matches `PublicRecordStatement`. Do not call `AuditService::append()` yourself.
- `publish()` joins the engine's open transaction (`DB::transactionLevel() > 0`, PublicRecordService.php:120) — the post seal, the back-pointer update, and the audit append are one atomic civic act.
- A thrown `ConstitutionalViolation` rolls back **all** of it (including the publish row); the engine records the rejection in a fresh transaction. Do not catch your own violation.

### Registration (the one required wiring edit)
`app/Domain/Forms/FormRegistry.php`:
- In `FORMS` (after F-ADV, ~line 174):
  ```php
  'F-SOC-001' => ['name' => 'Social Thread / Post',  'roles' => ['R-03']],
  'F-SOC-002' => ['name' => 'File Testimony in Hall','roles' => ['R-03']],
  ```
- In `HANDLERS` (after the Phase F block, ~line 383):
  ```php
  'F-SOC-001' => Handlers\SocialThreadPost::class,
  'F-SOC-002' => Handlers\SocialTestimonyFiling::class,
  ```
- Bump the `'103 forms'` count comment (FormRegistry.php:8,13) for tidiness (not pinned by a test, but reviewers flag drift).

---

## 4. Auto-bind reconciler + `EvaluateSocialStructureJob`

**`app/Services/Social/SubforumReconciler.php`** — pure idempotent reconciliation, no engine filing (subforum binding is structural plumbing, not a civic act):
- For a given jurisdiction's halls space, enumerate **live** governance objects: open bills, open referendum questions, gathering/active petitions, scheduled committee meetings, standing candidacies.
- For each, `firstOrCreate` a `social_subforums` row keyed on `(governing_object_type, governing_object_id)`. The partial-unique `WHERE deleted_at IS NULL` makes re-creation a no-op while letting soft-deleted rows coexist — this is the idempotency mechanism; pair it with `firstOrCreate`.
- For subforums whose object has closed (bill enacted/failed, election certified, petition resolved), flip `status='archived'` (never hard-delete — history stays browsable).
- Idempotent by construction: running twice over the same object set produces exactly one live subforum per object.

**`app/Jobs/EvaluateSocialStructureJob.php`** — the queued sweep:
- `implements ShouldQueue`; constructor takes a `$jurisdictionId` (or runs across all civically-active jurisdictions when null).
- `handle(SubforumReconciler $reconciler)`: for each civically-active jurisdiction (`CivicPopulation::of` above the activation threshold once Phase I lands; for K-1 use "has a seated legislature"), ensure the `public_square` + `halls` spaces exist (`firstOrCreate`), then run the reconciler.
- The flat→structured scaling toggle (small jurisdiction = one bare square subforum; large one grows the auto-bound tree) lives here, gated on `CivicPopulation::of`. K-1 ships the simple "always reconcile live objects" path; the threshold gate is a documented seam for Phase I.
- Dispatch points: from the `social:demo` command (inline, `queue.default=sync`); a scheduled nightly sweep is a documented follow-up (no CLK code — data + sweep, per the Phase-D/L precedent).

---

## 5. Moderation carve-out enforcement

The public square's **no-censor default and the four carve-outs live in `ConstitutionalValidator`, never in the handler ad-hoc.** Add an arm to the `check()` `match` (ConstitutionalValidator.php:276-299) and pure-assert methods (mirror `assertReferendumActModifiable`, :827).

A removal is modeled as a dedicated removal form/payload (e.g. an `F-SOC` removal arm carrying `carve_out`, `target_post_id`, and the justifying reference). The validator dispatches:

```php
'F-SOC-001', 'F-SOC-002' => null,                   // posting itself is never gated beyond R-03
'F-SOC-003' => $this->checkSocialRemoval($payload),  // the removal form (if introduced)
```

`checkSocialRemoval($payload)` enforces, by `carve_out`:

| Carve-out | Gate (where checked) | Citation on failure |
|---|---|---|
| **(default — no carve-out)** | reject outright | `'Art. I'` — "the public square cannot be censored" |
| **M-1 judicial order** | actor holds a **derived R-19/R-20** judge role (`RoleService` `hasJudicialSeat`, RoleService.php:653) **and** a valid case/order reference is present | `'Art. I'` / `'Art. IV §5'` — removal without a case id is structurally impossible |
| **M-2 protecting another's rights** | automatic for triad/privacy leaks (structural — the `FORBIDDEN_SUBJECT_TYPES` refusal already blocks triad content reaching plane A); doxxing = a judicial M-1 sub-case | `'Art. I'` |
| **M-3 per-user block** | **NOT a validator concern** — it is `social_memberships.block_user_id`, client-side, private, never `publish()`d, never audited | n/a (no chain entry) |
| **M-4 content-neutral anti-spam** | **system only** (null actor, behavior/volume thresholds from `constitutional_settings`); a human invoking it is rejected; viewpoint is never an input | `'Art. I'` |

Key rails:
- **Who may invoke is gated by DERIVED OFFICE ROLE, never a stored "moderator" bit.** M-1 → derived R-19/R-20; M-4 → system (null actor). There is no moderator column anywhere.
- **Halls are append-only.** No DELETE route for hall posts; the testimony copy in `public_records` is immutable (DB triggers). Corrections append a new record via `supersedes_record_id`.
- **Private (org/user) spaces self-moderate** via `social_memberships.role='owner'` — an in-handler ownership check on `is_private=true` spaces, NOT a derived office role. The validator does **not** clamp private spaces.
- Every M-1/M-2 removal is itself logged to `public_records` (append-only, hash-chained) and appealable — exactly the "rejected attempts recorded with an Article citation" rail.

*(Open decision: whether K-1 introduces an explicit `F-SOC-003` removal form or routes removals through an existing judicial form. Recommendation — model the removal as a new `F-SOC-003` so the `Art. I` carve-out gating sits in one place; flag to operator. If deferred, the validator still ships the no-censor default arm for F-SOC-001/002 = `null` and the source-scan privacy rail.)*

---

## 6. `FORBIDDEN_SUBJECT_TYPES` extension

`app/Services/PublicRecordService.php:34-39` — add the three local-only subject tokens (singular, lowercase — the guard lowercases input at :69; the existing entries `'ballot'`/`'location_ping'` are singular):
```php
public const FORBIDDEN_SUBJECT_TYPES = [
    'ballot', 'ballot_envelope', 'location_ping', 'residency_claim_pings',
    // Phase K-1 — the local-only social graph never reaches the public register / chain.
    'social_reaction', 'social_follow', 'social_membership',
    // (K-2 adds 'education_progress')
];
```
- This is **defense-in-depth**, not the mechanism: any accidental future `publish(subject_type:'social_reaction', …)` throws `InvalidArgumentException` (a programmer-error guard, NOT a `ConstitutionalViolation`) at the single write chokepoint. The real boundary is that reactions/follows/memberships are plain Eloquent inserts that **never call `publish()`**.
- No test pins the literal array (confirmed by the grep note in the extracted patterns), so the edit is safe and purely additive.

---

## 7. Controllers, routes, Vue pages (reusing the design system)

### Controllers (`app/Http/Controllers/Civic/`)
Copy `PetitionController` end-to-end. Constructor-inject `ConstitutionalEngine $engine, RoleService $roles, SettingsResolver $settings`.
- `PublicSquareController::index()` — `Inertia::render('Civic/PublicSquare', ['surface' => SurfaceMeta::for('civic/public-square'), …])`. Scope thread lists to the viewer's association chain (`$this->roles->associationsFor($user)` → `array_column($a,'id')` → `->when($chainIds !== [], fn($q)=>$q->whereIn('jurisdiction_id',$chainIds))`, PetitionController.php:50-61). **The un-associated viewer still READS** (public record) — only the create FormCard is gated; never 403.
- `PublicSquareController::store()` — `$request->validate([...])` then `$this->engine->file('F-SOC-001', $request->user(), [...])`, `return back()->with('status', …)`. **Never mutate the DB directly.**
- `HallsController` — same shape; the testimony action calls `$this->engine->file('F-SOC-002', …)` and echoes the resulting record href in the flash (parallels `committees.testimony → public_records`, routes/web.php:430-431, and PetitionController.php:128-130). **Do NOT copy `CommitteeController::testimony`** (a legacy non-form controller-direct `publish()` call, the wrong shape for a catalog form).

### Routes (`routes/web.php`, inside an existing `Route::middleware('auth')` group)
Use the petition shape; `->whereUuid` on all UUID bind params; `->name()` every route.
```php
Route::get('/civic/square', [PublicSquareController::class, 'index'])->name('civic.square.index');
Route::post('/civic/square/threads', [PublicSquareController::class, 'store'])->name('civic.square.store'); // F-SOC-001
Route::get('/civic/square/{thread}', [PublicSquareController::class, 'show'])->whereUuid('thread')->name('civic.square.show');
Route::get('/civic/halls', [HallsController::class, 'index'])->name('civic.halls.index');
Route::post('/civic/halls/{thread}/testimony', [HallsController::class, 'fileTestimony'])->whereUuid('thread')->name('civic.halls.testimony'); // F-SOC-002
```
**No DELETE routes** — public square is uncensorable, halls are append-only.

### Surfaces (`config/cga/surfaces.php`, copy the `civic/petitions` block at 464-491)
```php
'civic/public-square' => [
    'title' => 'Public Square', 'module' => 'civic', 'nav' => 'public-square',
    'roles' => ['R-03'],
    'forms' => [ ['id' => 'F-SOC-001', 'citation' => 'Art. I — the public square cannot be censored'] ],
    'citation' => 'Open resident discourse · residency-only · Art. I',
],
'civic/halls' => [
    'title' => 'Halls of Governance', 'module' => 'civic', 'nav' => 'halls',
    'roles' => ['R-03'],
    'forms' => [
        ['id' => 'F-SOC-001', 'citation' => 'Art. I'],
        ['id' => 'F-SOC-002', 'citation' => 'Art. II §2 — filing testimony seals it to the append-only record'],
    ],
    'citation' => 'Deliberation tied to bills/referendums/petitions/committees · append-only · Art. II §2',
],
```
**Register F-SOC-001/002 in FormRegistry BEFORE these entries resolve** — `SurfaceMeta::for()` throws on an unregistered id (SurfaceMeta.php:39-43) and `SurfaceMeta::form()` throws if any `forms[]` id is unknown (:71-84). The `nav` value MUST equal the nav.js item `id` (aria-current).

### Vue pages (`resources/js/Pages/Civic/`)
Copy `Petitions.vue` structurally — `PublicSquare.vue`, `Halls.vue`, `ThreadDetail.vue`, `HallDetail.vue`.
- `<script setup>`: `{ computed, ref } from 'vue'` + `{ Link, router, useForm, usePage } from '@inertiajs/vue3'` + design-system components.
- `defineProps` mirrors the controller payload (`surface` required Object, lists Array default `[]`).
- Wrap everything in `<PageScaffold :surface="surface">` with `#intro`/`#about`.
- Flash + rejection banners: `flashStatus = computed(()=>page.props.flash?.status ?? null)` and `constitutionError = computed(()=>page.props.errors?.constitution ?? null)` → two `<Banner v-if=… tone>` (this is how the "rejected with an Article citation" rail surfaces).
- Create form: `useForm({...})` + `<FormCard :form="formMeta('F-SOC-001')" :inertia-form="create" @submit="submitCreate">` with `<Field>` slots; submit does `create.transform(d=>({...d, form_id:'F-SOC-001'})).post('/civic/square/threads', {...})`. `form_id` rides EVERY write payload.
- **ZERO new CSS** — use `stack`, `cluster`, `citation`, `gloss`, `cc-small`, `eyebrow`, `var(--space-N)` only; reuse `Card`/`Stat`/`Banner`/`FormCard`/`Field`/`StatusBadge` from `@/Components`.

### Nav + i18n
- `resources/js/Navigation/nav.js`: add a `visibility:'all'` section (public square is open to everyone, like `petitions`/`judiciaryPublic`):
  ```js
  { key: 'square', titleKey: 'nav.publicSquare', visibility: 'all', items: [
      { id: 'public-square', labelKey: 'nav.publicSquare', icon: 'message-square', href: '/civic/square', phase: 'K' },
      { id: 'halls',         labelKey: 'nav.halls',        icon: 'landmark',       href: '/civic/halls',  phase: 'K' },
  ] },
  ```
  `id` MUST equal the surface `nav` value. Use `phase:'K'` so items render "Planned · Phase K" disabled until `phasesLive` flips.
- Add `nav.publicSquare` / `nav.halls` under the `"nav"` block in ALL FIVE locale files (`en/es/ar/hi/zh-Hans`; en.json:14 canonical) — a missing key renders the raw key string.

---

## 8. `social:demo` seeder + phasesLive

### `app/Console/Commands/SocialDemoCommand.php`
Model exactly on `PhaseEDemoCommand` (Laravel 12 auto-discovers commands — no `Kernel.php`).
- `protected $signature = 'social:demo {--fresh : tear down prior demo state and reseed}';`
- Constructor injects `ConstitutionalEngine $engine, RoleService $roles` (promoted readonly).
- `handle()`: `config(['queue.default' => 'sync'])` (so `EvaluateSocialStructureJob` fires inline); resolve San Marino by slug `smr-1-san-marino` (PhaseEDemoCommand.php:109); **pre-check dependency state** — a seated legislature + ≥N active `residency_confirmations` (the demo depends on `elections:demo` having run; for halls tied to bills, on the Phase C bill demo). Error cleanly if missing (PhaseEDemoCommand.php:155-203 pattern), never half-seed.
- If `--fresh`: `teardown()` then reseed; else if standing, report-and-exit idempotently (PhaseEDemoCommand.php:224-230).
- Each step rides its own small `DB::transaction`; the command WRITES to the live dev DB and does NOT roll back (PhaseEDemoCommand.php:96). It replays the SAME engine sequences the K-1 tests roll back, but persists them: create square/halls spaces, run the reconciler to bind subforums to the demo bills, file a few F-SOC-001 posts as demo residents, file F-SOC-002 testimony on a live bill (→ sealed `public_records` row + back-pointer).
- `demoResident()`: reuse `PhaseEDemoCommand.php:1129-1151` — a User + an active `residency_confirmations` row (the R-03 substrate); leave residents in place (users are append-only by design).
- End with `$this->call('audit:verify')` (PhaseEDemoCommand.php:1235).

### `--fresh` teardown (RESPECT APPEND-ONLY)
- Tag every demo row (name/email/title tag, e.g. `[K-Demo]`, `k-demo` emails) — teardown selects by tag in one `DB::transaction`.
- `public_records` + `audit_log` are **NEVER deleted** (DB immutability triggers raise on UPDATE/DELETE).
- A `social_thread` with a `published_record_id` (testimony sealed) is **soft-deleted** (the `cgc_ip_register` precedent, PhaseEDemoCommand.php:969-999) — leaves the page via `deleted_at` while the immutable record/chain stands. Plain demo threads with no sealed record are force-purged.

### phasesLive advance (LAST wiring step)
- `app/Http/Middleware/HandleInertiaRequests.php:73` — `'phasesLive' => ['A','B','C','D','E','F','K']` (append `'K'`, pending operator letter confirmation).
- Flip it in the **same FE batch** as the pages ship (convention nav.js:56,77) so the sitemap stays visible-but-disabled until ready; flipping early leaves live links pointing at empty surfaces.

---

## 9. Tests — the live-pg posture (every DB-touching pin)

Extend `Tests\TestCase`; use the `LivePgConnection` trait (`$this->livePg('pgsql_k1_social')`) or the inlined helper. Envelope (lift from `CaseLifecycleTest.php:65-72,251-257`):
```php
$conn = $this->livePg('pgsql_k1_social');
$originalDefault = DB::getDefaultConnection();
DB::setDefaultConnection('pgsql_k1_social');
app(RoleService::class)->flush();         // roles are derived+cached — flush before & after
$conn->beginTransaction();
try {
    $engine = app(ConstitutionalEngine::class);
    $filed  = $engine->file('F-SOC-001', $resident, [...]);   // ->recorded carries thread_id/post_id
    // ... assert effects; pin rejections via catch (ConstitutionalViolation $e) { assertSame('Art. I', $e->citation); }
} finally {
    while ($conn->transactionLevel() > 0) { $conn->rollBack(); }
    DB::setDefaultConnection($originalDefault);
    app(RoleService::class)->flush();
}
```
K-1 test files (added to `FuturePhasePlaceholdersTest.php:120`): `SocialSchemaTest.php`, `PublicSquareTest.php`, `HallsTestimonyTest.php`, `SubforumReconcilerTest.php`, `SocialModerationCarveoutsTest.php`.

**CI source-scan risk:** the existing `ElectionClockTest`/`CgcIpPublicDomainTest` scans iterate all of `app/` and strip comments. Keep `social_reaction/follow/membership` writes off the `publish()` path entirely; do not name a forbidden token in non-comment code in a way that trips the scans.

---

## 10. Open decisions + risks

**Open decisions (flag to operator):**
1. **Phase letter** — roadmap says `K`; confirm before appending to `phasesLive`/`nav.js phase:`. MEMORY warns of self-assigned collisions. Do not invent a new letter.
2. **Removal form** — introduce explicit `F-SOC-003` (removal, carve-out-gated) vs. route removals through an existing judicial form. Recommendation: `F-SOC-003`, so the `Art. I` carve-out gating sits in one validator place.
3. **`module()` for F-SOC-002** — `'records'` (it publishes to the register, recommended) vs `'social'`. Either valid; pick and stay consistent. (F-SOC-001 = `'social'`.)
4. **Subforum subject_type token vocabulary** — `social_subforums.governing_object_type` and the F-SOC-002 `subject_type` should match the existing public_record convention (`'bills'`/`'referendums'`/`'petitions'`/`'committee_meetings'`/`'candidacies'`). Pin the exact tokens before shipping (used in two places).
5. **Flat→structured threshold** — K-1 reconciles all live objects; the `CivicPopulation::of` gate that toggles auto-bound room creation is a Phase-I seam. Confirm K-1 ships the simple path.

**Risks:**
1. **Moderation default inversion** — the entire instinct of a forum is "admins can delete." K-1 must ship the *opposite* default (uncensorable square) and bury removal behind derived-office carve-outs. A reviewer expecting a moderator role will be surprised; the `Art. I` no-censor validator arm is the backstop.
2. **Privacy regression via `publish()`** — the one way the local-only graph leaks is an accidental `publish()` of a reaction/follow/membership. Mitigated by the `FORBIDDEN_SUBJECT_TYPES` tripwire + the source-scan pin, but the *real* defense is keeping those writes as plain Eloquent inserts.
3. **Two-chain-rows confusion** — F-SOC-002 legitimately produces two chain entries (publish + form success). A test that asserts exactly one will fail; the plan pins both. Document it.
4. **`published_record_id` = uuid not seq** — `PublicRecord` has two keys (`seq` int PK, `id` uuid). The back-pointer must store `->id`. Storing `->seq` is a silent foreign-key-shape bug.
5. **Demo dependency ordering** — `social:demo` needs `elections:demo` (seated legislature + residents) and the Phase C bill demo (live bills for halls binding) to have run first. Hard pre-checks prevent half-seeding.
6. **`SurfaceMeta`/FormRegistry ordering** — register the forms before the surface entries, or the pages 500 on render before the slice is even testable. Wire FormRegistry in Slice K1-B/C, surfaces in K1-F.

---

## Relevant file paths (absolute)
- Engine/pipeline: `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Domain\Engine\ConstitutionalEngine.php`, `app\Domain\Forms\Contracts\FormHandler.php`, `app\Domain\Forms\FormRegistry.php`
- Templates: `app\Domain\Forms\Handlers\PublicRecordStatement.php`, `PetitionCreation.php`
- Publish/privacy: `app\Services\PublicRecordService.php`, `app\Models\PublicRecord.php`
- Validator: `app\Services\ConstitutionalValidator.php` (check() match :276-299; pure-assert :827)
- Roles: `app\Services\RoleService.php` (R-03 :203-211; hasJudicialSeat :653)
- Controller/routes/surfaces: `app\Http\Controllers\Civic\PetitionController.php`, `routes\web.php`, `config\cga\surfaces.php`
- Frontend: `resources\js\Pages\Civic\Petitions.vue`, `resources\js\Navigation\nav.js`, `resources\js\i18n\*.json`, `app\Http\Middleware\HandleInertiaRequests.php`
- Migration template: `database\migrations\2026_10_08_000001_create_peer_upgrade_agreement_tables.php`
- Demo/test templates: `app\Console\Commands\PhaseEDemoCommand.php`, `tests\Concerns\LivePgConnection.php`, `tests\Constitutional\FuturePhasePlaceholdersTest.php`
- Design sources: `docs\plans\CGA_PHASE_G_AND_BEYOND_ROADMAP.md` §4 (:265-312, :509-521), `docs\plans\phase-g-continuation\K3-social-layer-matrix.md`
