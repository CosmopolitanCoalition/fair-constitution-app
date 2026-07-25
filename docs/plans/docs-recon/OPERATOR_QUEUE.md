# Operator Queue — open decisions (2026-07-25, 15:55)

One page. Everything waiting on the operator, with lane 7's recommendation where it has one.
Nothing here is decided; the recommendations exist so a "yes" is a complete answer.
Sources: [BUILT_INVENTORY §7](BUILT_INVENTORY.md) risk register · [REVIEW_ROUND_2.md](REVIEW_ROUND_2.md).

---

## A. Gating a lane's next step

| # | Decision | Why it's yours | Lane 7 recommends |
|---|---|---|---|
| A1 | **The setup-endpoint class.** One endpoint fixed (`4b17b08`); **8 remain** with no founding lock — `startMapData` (a live planet-scale ETL trigger on a founded world), `controlMapData`, `saveCosmicAddress`, `setMode`, `detectStep1`, `activateStep1`, `completeStep3`, `completeStep4`. Guards are ad hoc, not by rule. | It is app code outside any lane's declared path; someone must be assigned | **One systematic pass** keyed on "does this have a post-founding consequence?", using lane 13's proven pattern. Assign to lane 13 (has the pattern + context) or lane 2 (owns launch). **Before any public node exists.** |
| A2 | **Per-kind `racePlan()`.** A run-level block means an illegal Type B half kills the lawful Type A district races with it — **30,262 legislatures**, 23.8% of planet seats. Found independently by lanes 3 and 4. | Touches the elections engine | Make it per-kind: schedule the lawful races, defer only the illegal half. Until then ~30k chambers cannot hold an election. |
| A3 | **May lane 11 clone the operator's voice?** Its XTTS bench already generates Spanish from a 20-second reference of him. Charter sanctions it for evaluation; **no code-level provenance/quarantine gate exists** (unlike lane 10's). | It is his voice and likeness | Answer the *policy* question either way, then require lane 11 to adopt lane 10's `audio_provenance` + fail-closed quarantine so the answer is enforced, not remembered. Benchmarking may continue meanwhile; nothing cloned publishes. |
| A4 | **`achievements.earned_at` → coarse DATE.** It is a full timestamptz and it federates unredacted; the charter's privacy rail wanted a date. | Schema change on a shipped table | **Take it now.** Both databases hold zero achievement rows, so it is a one-migration fix today and a data-truncation decision after the first real medal. |

## B. Values calls (no deadline, but they shape what gets built)

| # | Question | The situation |
|---|---|---|
| B1 | **The untranslatable back catalogue.** `public_records` rows are immutable by trigger, so anything already published carries an empty translation set **permanently** — no locale ever addable. WF-SYS-03 ("records publish *with* translations") is the only hard constitutional mandate in Phase N. | Accept a permanently non-compliant back catalogue, or add a compliant re-publication path. |
| B2 | **The locale roster excludes major languages.** The base derives from a geodata map of *official* languages per country, and the ladder only subtracts — so **Telugu, Marathi, Punjabi, Gujarati, Kannada, Malayalam, Hausa, Yoruba, Igbo, Javanese** can never enter, despite NLLB support and hundreds of millions of speakers each. **Re-verified 2026-07-25 against lane 5's new single registry (`101c315`, `scripts/i18n/languages.py`, 117 entries): all ten are still absent.** | For a cosmopolitan template whose Art. I rights do not key off officialdom, the base list deserves an explicit widening pass. **Now cheap:** the five drifting lists have collapsed into ONE generator, so widening is a single-file edit plus `python3 scripts/i18n/languages.py --write`. Decide the *principle* (speakers? NLLB coverage? official status?) and the roster follows. |
| B3 | **Seating-doctrine scope qualifier.** The law says no textbook apportionment method appears "anywhere in seat allocation" — but **largest-remainder is used today** for committee seat apportionment across the type_a:type_b ratio (`CommitteeService.php:28,56,79`, Phase C, shipped). This is a live contradiction: lane 12's publish lint now **blocks accurate copy** about the bicameral committee split. | Districting doctrine is untouched. One line — the law governs *jurisdiction and legislature seat allocation*; committee splits inside a chamber are a different layer — resolves it. **Wording is the operator's; nobody should "fix" either side unilaterally.** |
| B4 | **Fleet git rule.** The shared index has swept three lanes' in-flight work (including lane 7's own commit taking 69 of lane 5's files). | Recommend making `git commit -- <path>` **mandatory** fleet-wide + a `git show --stat` file-count check in board rule 7. |

## C. Cheap and time-sensitive

- **Lane 10's pilot script still carries the wrong seat-rule line** and waits on the operator to record. Free to fix now; a reshoot later costs a session. Vetted replacement is in the 12:38 board ruling.
- **Lane 15's §5 lint defect is live in lane 12's publish gate** — it blocks the fleet's own approved wording (`FAC-1`, `FAC-2`) and accurate committee copy (`APP-1`, see B3). Fix at the source, bump `upstream_version`, lane 12 re-imports. Content lanes told: treat a block on approved wording as a **lint** defect, not a copy defect.
- **The staged fleet broadcast** (review verdicts + fleet-wide findings) is written and held for the operator's preview per his standing rule.

## D. Answered by lane 7 — no action needed (recorded so nobody re-opens them)

GATE-3's predicate was wrong, not lane 14's seeding (the nonprofits are real entities, not
synthetic data) · lane 12 adopts lane 9's shipped `claims_check` rather than authoring a second ·
lane 10 reconciles the language-allowlist drift as upstream authority · the faction freeze is
**lifted** for lanes 9–11, lane 12 on prose only until the lint is patched · lane 5 builds its
checker standalone, CI bring-up is not 40-day-critical · the Register.vue copy stays while lane 5
pilots delivery, lane 6 softens if unproven when its wave arrives · lane 13's walkthrough-then-design
ordering was superseded by the operator in-chat, correctly.

## E. Closed this round

**Risk #14 — no environment guard on any artisan command** (lane 4, `2ff1af6`, operator-triggered):
was a **live** exposure, not a Phase-O one — `elections:demo` would mint 40 permanent users with
password `demo` against whatever database `.env` pointed at. Now guarded by a declared **world
property** (`instance_class=scale_demo` OR `game_mode=sandbox`), never an env var or code flag, per
the no-dev-exceptions rule. Fails closed to production; the CI-2 boot assertion refuses to *serve*,
not to boot, so a bad state never locks the operator out of the console. Side effect: Phase O's
`instance_class` persistence landed early, because this needed it.

**D-09 age settings — CLOSED as NOT APPLICABLE** (operator ruling via lane 13, `066567f`):
`age_of_majority = 18` is real Template/website constitutional text, but it is **not a property of
the simulated world** — this is a game, every account is a character, and there is no date of birth
in the system. Nothing ships: no settings keys, no columns, no rail, no test. Lane 13 left both of
its earlier wrong framings on the record so the next reader stops sooner. **Reassign to whoever owns
Template text and website copy, or close outright — it is not a code deliverable.**
*(This removes the "age values" item from the operator's decision list.)*

**Risk #17 — the documented install path** (lane 2, `92ca3be`, verified): the public path now runs
`deploy.sh --public-url`, which regenerates the app key and seeds clocks, requires https, and
**refuses `--seed`** so demo data cannot enter a real launch. README re-scopes `get-started` to
local/LAN. Residual: a LAN node still boots on the committed key with debug on — scoped, noted, not
blocking.
