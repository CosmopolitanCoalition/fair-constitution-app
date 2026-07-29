# Walk journey — amend a constitutional setting (R-C)

*Lane 3, 2026-07-29. The walkable end-to-end amendment workflow the operator
ruled must exist (R-C, V3_SYNTHESIS_PLAN §10). Authored here for lane 6 to fold
into `PLAYTEST_WORKSHEET.md`. The loop is REAL and verified — this card is the
human path through it, not a mock.*

## What it proves

A legislature changes one of its own amendable constitutional settings **through
the real act pipeline** — not an admin toggle. The value moves only when the
chamber ENACTS it, and the change lands, dated and attributed, on the public
amendments ledger. Bounds are hardened: an out-of-range value is refused before
any vote, with its citation, and the refusal itself is chained.

## Who

A seated member of an **active** legislature (role R-09). Amendments are acts of
the chamber, so a non-member has no door here (they watch the result on the
public ledger).

## The walk

1. **Open the register.** Go to the chamber → **The chamber's rules**
   (`/legislatures/{id}/settings`). Every amendable setting shows its resolved
   value, where that value comes from (this chamber, or inherited from a parent
   jurisdiction), its hardened bounds, and the act that last set it.
2. **Pick a setting and propose.** Click **Propose change** on a row — e.g.
   `election_interval_months` (currently 60). The propose panel pre-targets it.
3. **Type a value; watch the pre-flight.** Enter `48`. The panel live-checks the
   value against the hardened bounds (a pure pre-flight — nothing is filed yet).
   Try an out-of-range value (e.g. `legislature_min_seats` → `3`) and the panel
   shows the refusal + citation *before* any filing — the engine will not carry it.
4. **File the amendment.** With an in-range value, click **Propose amendment
   (F-LEG-031)**. This files F-LEG-031 through the engine, which INTRODUCES a
   pre-targeted setting-change bill (`act_type = setting_change`). Nothing has
   changed yet — the flash confirms: *"it takes effect only when the chamber
   enacts it."* (The **or draft the full bill →** link is the long-form path:
   the same act with custom law text, filed as F-LEG-003 from the Bills page.)
5. **Carry it to a vote.** The bill runs the ordinary lifecycle — committee if
   referred, then a peg-quorum **floor vote**. On adoption the bill flips to
   PASSED and `EnactmentService` fires.
6. **Enactment applies it.** At enactment the engine writes the `setting_changes`
   ledger row (old → new), mutates `constitutional_settings`, records the
   enacting act, and re-derives any dependent clock timers. Bounds are
   re-checked here too (TOCTOU) — a value that went out of range between filing
   and enactment is still refused.
7. **Read the receipt.** Go to **Amendments** (`/system/amendments`). The change
   appears newest-first: *where* it applied, the setting, `old → new`, and the
   enacting act number. The register on the settings page now shows the new
   value with its new provenance.

## Verified (2026-07-29)

- **F-LEG-031 is reachable and files a real bill** — run live on the dev box's
  active legislature: `engine:file F-LEG-031 --actor=<member> --payload=…` sealed
  audit seq 89721 and introduced bill `019fad9c…` (`election_interval_months` →
  48, status `introduced`, `act_type = setting_change`). The Settings-page door
  files the identical form; the CLI is its parity twin (ruling 10).
- **The refusal path is live** — filing F-LEG-031 against a jurisdiction with no
  active legislature returns *"No active legislature governs this jurisdiction"*
  · Art. VII, chained as a rejected entry.
- **Enactment → ledger** is pinned by `SettingEnactmentTest` (the setting mutates
  only at enactment; `setting_changes` written; dependent clocks re-derived).
- **The ledger renders** — `SystemClocksAmendmentsTest` pins that
  `/system/amendments` renders the `setting_changes` feed.
- **The write is gated** — `PublicProceedingsGuestTest` pins that a guest POST to
  `/settings/amend` bounces to `/login` (watching is public; amending is not).

## Out of scope for this walk

**Dual-door keys** (`judiciary_is_elected` + the Phase-L monetary levers) do NOT
travel this path — they require constituent consent (Art. IV §3) and route
through the Door-2a flow. This walk is the ordinary R-C amendable settings
(`election_interval_months`, `max_days_between_meetings`, and the rest of the
17-key register).
