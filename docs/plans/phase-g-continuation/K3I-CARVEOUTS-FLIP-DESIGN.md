# K3-I — The Carve-outs + the Legitimacy Flip + the Legal-Compliance Floor (build spec)

> The centerpiece of K-3. Ships TWO things together: (A) the four CONSTITUTIONAL carve-outs (M-1..M-4)
> + the legitimacy-gated seatedness flip, and (B) the M-S/M-5 PHYSICAL-LAW legal-compliance floor for
> illegal content (distinct from the viewpoint carve-outs). Memory: [[project-phase-k3-illegal-content]],
> [[project-phase-k3-matrix]]. Resume from this doc after compaction.

## A. The four constitutional carve-outs + the flip

Removals from a PUBLIC room are ONLY the four carve-outs (otherwise uncensorable, Art. I). The v12
appservice (immutable creator) is the SOLE emitter — no human ever holds a Matrix power level.

| Carve-out | Who | Bootstrap (no seated govt) | Post-flip (seated) | Matrix action |
|---|---|---|---|---|
| **M-1 judicial** | judiciary | unavailable (no judge) | live R-19/R-20 attestation + case/order id | soft-fail (reversible) |
| **M-2 rights** (doxxing/triad) | relay→judiciary | operator-board R-08 relays, neutral+logged | R-19/R-20 judicial sub-case | hard redact + logged |
| **M-3 per-user block** | each user | client-side `m.ignored_user_list` | unchanged — never an appservice action | n/a |
| **M-4 anti-spam** | system | operator sets content-neutral knobs | seated legislature owns knobs | soft-fail / rate-limit |

- **The flip** keys on local `Legislature::STATUS_ACTIVE` (the SAME derived fact as G-VER Meter A→B / G6
  `LocalAutonomyService`). Below it ⇒ operator-board (R-08) relay; the instant a legislature is seated ⇒
  the appservice REQUIRES an R-19/R-20 `StandingAttestation` for M-1 and stops honoring the operator.
  Binary, automatic, not seizable. **The Matrix power level NEVER moves** (stays the appservice creator).
- **`ModerationFlipService`** = `verifyAttestation` (AttestationService, fails closed) → pivot on
  STATUS_ACTIVE → `logFlip()` to public_records kind `moderation_flip` + matrix_carveout_log.
- **`CarveoutEmitterService`** translates a verified F-SOC-003 success into the m.room.redaction (M-1/M-4
  soft-fail; M-2 hard redact), always logging matrix_carveout_log. Reuses F-SOC-003 (`SocialRemoval`) +
  `ConstitutionalValidator::checkSocialRemoval`.

## B. The M-S / M-5 legal-compliance floor (physical law — NOT a viewpoint carve-out)

The four carve-outs are VIEWPOINT questions. Illegal/dangerous content (CSAM, true threats) is a
PHYSICAL-LAW question the operator is criminally liable for — a different axis, content-neutral, off the
constitutional plane. **Generalized:** this is the universal "physical hand-brake on reality" every phase
with a physical-law surface plugs into (content now; market/economic in Phase M). **Code-hardened** —
the category set is a compiled enum, grown ONLY by code release per phase, never by an in-game act.

- **M-S (proactive, mechanical/SYSTEM):** a pre-publication content-neutral hash-scan admission filter in
  front of the media repo (element-hq `matrix-content-scanner-python` + an operator script). ONLY inputs =
  a configured known-illegal hash list + the media hash; NO semantic/ML classifier. Blocks known-illegal
  media BEFORE it becomes an event/federates. Least-abusable (no published event to censor).
- **M-5 (reactive, OPERATOR PLANE):** removal of already-posted illegal material. Authorized by an
  `OperatorAccount` via key-possession (the `AuthorityFlipService $operatorUserId` pattern), **zero
  R-codes, attestation_id=NULL** — NOT a constitutional office. Routed through the SAME logged v12
  appservice emitter (clamp channeled, not bypassed). Closed **legal_basis enum: `csam_hashmatch |
  court_order_specific | true_threat`** (a viewpoint basis is UNREPRESENTABLE). **Per-item only.**
- **THE CRUX (quarantine ≠ purge):** Synapse quarantine KEEPS bytes; redaction RETAINS them in the DB.
  CSAM needs a new **`ACTION_PURGE`** = `DELETE /_synapse/admin/v1/media/<server>/<media_id>` (destroys
  file+thumbnails); a whole room = `…/rooms/<id>/delete {block,purge}`. Legal sequence (§2258A + parallels):
  **PRESERVE** (evidence copy, ≥1yr) → **REPORT** (NCMEC CyberTipline, operator-console + operator creds)
  → **PURGE**. Federation: purge LOCAL-only, redactions best-effort — but every operator is INDEPENDENTLY
  criminally liable, so honest peers purge on their own duty (not a consensus event).
- **M-5 FLIPS TO BOTH, not a handoff (operator decision):** M-5 is ALWAYS operator-held (liability is
  permanent). Once constitutional actors are seated, BOTH hold it — operator DEFERENCE for the physical
  action, PLUS a MANDATORY DISCLOSURE: the F-SOC-004 handler emits a REFERRAL record to the seated bodies
  so the in-game justice can ALSO act (its own M-1 case / governance response). Operator = real-world
  bytes+report; constitutional actors = the in-game response. Each in its lane.
- **Scanning on-board vs external:** local hash list = fully OFFLINE matching (the DEFAULT; privacy rail —
  never ship content to a cloud by default). External only for: list updates (or sneakernet), optional
  cloud-API scanners (opt-in), the NCMEC report (eventual connectivity). LAN/air-gapped: scan+purge work
  offline via a sideloaded list; report defers to connectivity. CGA ships the SEAM + a local-list provider;
  the OPERATOR supplies the actual lists (access-controlled, their legal credentials).

## C. Guardrails (CI-pinned invariants)
1. Closed `legal_basis` enum — a viewpoint/discretionary basis is REFUSED (mirror checkSocialRemoval).
2. Per-item targeting only — never a class/viewpoint/whole-server.
3. **M-5 EXCLUDED from server-ACL writers** — `matrix_server_acls` carve_out stays `m1`/`m4` only; a
   country's illegal content can NEVER silence its residents.
4. `attestation_id=NULL` on every M-5 row (+ `operator_signed_by`) — can't be forged as a judicial M-1.
5. NO CSAM hash/locator in any published log — transparency = count + list-source only.
6. Scanner content-neutral — no semantic classifier in the admission path.
7. `csam_hashmatch` SURVIVES the flip (operator liability non-negotiable); only court_order/true_threat
   discretion ALSO migrates to the seated judiciary's M-1 lane (Art. IV §5-challengeable).
8. ACTION_PURGE reserved to csam_hashmatch and actually DELETEs local bytes (not soft_fail/quarantine).
9. Mesh discontinuity detector — `m5_legal` without a matching `legal_compliance_removal` record = a
   censorship-without-an-order red flag (reuse §5.4 + ChainReconciliationService).

## D. Schema changes (additive; do NOT edit shipped 2026_11_05_000001)
- New migration `2026_11_05_000002`: extend `matrix_carveout_log_carve_out_check` +`m5_legal`; extend
  `matrix_carveout_log_action_check` +`purge`; **LEAVE `matrix_server_acls_carve_out_check` at m1/m4**.
- New table **`legal_compliance_removals`** (append-only, immutable trail): id, matrix_event_id,
  matrix_room_id, operator_account_id, legal_basis (CHECK), statutory_citation?, matched_list_source?,
  public_records_id?, jurisdiction_id?, is_seated_at_time, referral_record_id? (the disclosure to seated
  bodies), timestampsTz, NO soft-deletes.
- `MatrixCarveoutLog` +`CARVE_M5_LEGAL='m5_legal'`, +`ACTION_PURGE='purge'`.
- `PublicRecord::KINDS` +`'moderation_flip'` +`'legal_compliance_removal'`.

## E. Sub-slice build order
- **K3-I.1 — DONE (8de87c7).** schema (the migration + LegalComplianceRemoval model + MatrixCarveoutLog
  consts + PublicRecord KINDS) + LegalComplianceSchemaTest (4 pins; m5_legal/purge in CHECKs;
  legal_compliance_removals + its enum; server_acl carve_out STILL m1/m4 only).
- **K3-I.2 — DONE (30d9019).** `ModerationFlipService` + `FlipDecision` (the M-1..M-4 flip on
  STATUS_ACTIVE; bootstrap operator-relay vs seated R-19/R-20 attestation, fails closed; M-3 refused
  client-side; logFlip → moderation_flip + matrix_carveout_log) + `ModerationFlipTest` (4 pins; the
  service holds NO Matrix client → power level structurally never moves).
- **K3-I.3 — DONE (4d287b1).** `CarveoutEmitterService` (reuses the F-SOC-003 SHAPE gate + the flip
  AUTHORITY gate → m.room.redaction; M-1 soft-fail, M-2 hard, M-4 anti-spam content-neutral; seal-first
  then best-effort redact) + `MatrixCarveoutEmitterTest` (6 pins; M-3 never an appservice action;
  down-homeserver honesty).
- **K3-I.4 — DONE.** `LegalComplianceRemoval` F-SOC-004 handler (systemOnly/operator-plane, NO R-codes;
  M-5; ACTION_PURGE for csam; the disclosure REFERRAL when seated) + `LegalComplianceService` (operator
  orchestrator + the purge/redact emit) + `checkLegalComplianceRemoval` validator + the media SCAN SEAM
  (`MediaScanProvider` interface + `LocalHashListScanProvider` offline default + `MediaAdmissionGate` +
  config `matrix.scan`) + `MatrixClientService::purgeEvent` seam + `LegalComplianceTest` (all 9 §C
  guardrails as pins). **NEXT: the consolidated adversarial workflow review of the whole H+I core.**

## F. Dev-buildable now vs deferred
- **Now (dev stack):** all of A–E above — the carve-out path, the flip, the M-5 path end-to-end, the scan
  SEAM (interface + admission gate + stub hash list), every CI-invariant test.
- **Operator-config / rig / deferred:** real IWF/NCMEC/PhotoDNA hash-list integration (operator creds);
  live Synapse DELETE/quarantine/MCS wiring + cross-instance purge propagation (rig); NCMEC submission.

## G. Adversarial-review outcome (3 independent reviewers, post-I.4)
Structural invariants CONFIRMED airtight: viewpoint-impossibility (closed enums + shape gate), power-level
-never-moves (no Matrix client on the flip service), the plane wall (RoleService never read for the operator),
single-transaction atomicity (trail+log+record), systemOnly (no citizen files F-SOC-004), M-3-never-an-
appservice-action, the form-count (108). Findings FIXED in the hardening pass (suite 26 K3-I pins green):
- **must-fix — CSAM hash leak on the REJECTED path:** the validator refuses a hash-bearing filing, but the
  engine sealed `sanitize(payload)` to the chain and `SENSITIVE_KEYS` didn't include the CSAM family →
  *tripping* the guard leaked the hash. FIXED: added the hash/locator family + `operator_account_id` to
  `ConstitutionalEngine::SENSITIVE_KEYS` (sanitize already lowercases + recurses). Pinned.
- **must-fix — wrong-key attestation verify:** `ModerationFlipService` verified every attestation against
  OUR key. FIXED: `issuerKeyFor()` resolves the CLAIMED issuer's pinned key (self → our key; peer →
  `federation_peers.public_key`; unknown → null → FAIL CLOSED). Pinned (foreign-issuer refused).
- **should-fix — free-text smuggling:** `matched_list_source` (→ public body) / `statutory_citation` (→ trail)
  could carry a URL/hash. FIXED: validator rejects URL/hex/base64 shapes + length-caps both. Pinned.
- **should-fix — recursive forbidden-key scan:** the validator's scan was top-level only. FIXED: recursive +
  case-insensitive `payloadCarriesForbiddenKey`. Pinned (nested `evidence.SHA256` refused).
- **should-fix — `emitAntispam` permitted-guard** added (fail closed if m4 ever becomes refusable).
- **nit→done — DB CHECK** `matrix_carveout_log_attestation_seated_check (attestation_id IS NULL OR
  is_seated_at_time)` (migration 000003): a forged "judicial order before there is a judge" is now
  impossible at the DB layer.

**Still deferred to K3-N (rig), documented not fixed:**
- `MatrixClientService::purgeEvent` performs the appservice REDACTION but the media-byte DELETE
  (`DELETE /_synapse/admin/v1/media/...`, admin token) is rig-gated — CSAM bytes persist on disk until the
  rig wiring lands; the durable §2258A TRAIL is what the dev stack guarantees. A `physical_removal_status`
  on the trail (deferred vs done) should accompany the rig wiring so nothing reports "purged" falsely.
- Attestation↔jurisdiction SCOPE binding: a valid R-19/R-20 attestation proves "a judge" not "a judge of
  THIS jurisdiction" (consistent with F-SOC-003's global role gate today). Bind to the governing
  legislature when the rig peer-judge path is built.
