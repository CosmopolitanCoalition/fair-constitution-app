# Delta Inventory — The Three Authorities vs Built Reality

Lane 7 (Master Docs Digest), 2026-07-24. Sources compared:

| Authority | Extract read | Compared against |
|---|---|---|
| `Fair_Constitution_Labeled.docx` | `docs/extracted/fair_constitution.md` (38.6k chars, full) | CLAUDE.md constraints, schema baseline, FormRegistry, settled rulings |
| `CGA_Architecture_Plan.docx` v1.0 Feb 2026 | `docs/extracted/architecture_plan.md` (25.8k chars, full) | Live stack, 183-table baseline, phases 0–5+G+K1+K3, G→O charter |
| `CGA_Constitutional_Roles_Forms_Chart.xlsx` | `docs/extracted/roles_forms_chart.md` (7 sheets, full) | `app/Domain/Forms/FormRegistry.php`, mechanical ID census |
| `Topic_Knowledge.xlsx` (not an authority — characterized) | sampled | — |

Verification method: full reads of the extracts + mechanical ID census
(`F-`/`CLK-`/`R-` patterns, code vs chart, distinct-set comparison) + targeted
schema/registry greps. Census artifacts in the session scratchpad.

---

## 1. Verdict summary

| Dimension | Authority says | Reality | Delta class |
|---|---|---|---|
| Committee/voting-system model | **Faction-based** (Art. II §4, Art. III §2, Art. II §2) | **No faction layer** — organizations + polymorphic endorsements; individual-level proportionality | ⚑ FLAGSHIP amendment |
| Forms | Chart: 104 | Registry: **109 canonical** (+6 documented alias IDs) | Chart +5, CLAUDE.md count stale (fixed) |
| Clocks | Chart: none cataloged | **CLK-01..CLK-21** live | New chart sheet needed |
| Roles | Chart: R-01..R-30 | Same (R-00 = UI visitor convention only) | ✓ complete |
| Architecture plan | Laravel 11 · Meilisearch · Pinia SPA · ActivityPub · 76 weeks | Laravel 12 · Inertia · custom mesh/FF&C · Matrix+LiveKit · 0–5+G+K1+K3 built, G→O charter forward | Full-document refresh |
| Art. V §6 Cultural Institutions | In template | **BUILT** (`cultural_institutions` + `multi_jurisdiction_votes` kind) | ✓ none |
| Art. V §5 age of consent/majority (18) | Amendable variable | **No app counterpart anywhere in schema** | Gap (defer to L/M) |

---

## 2. Template findings → feed `docs/findings/PROPOSED_AMENDMENTS.md`

**T-1 (FLAGSHIP) — Faction language vs the polymorphic organizations model.**
Template text still allocates by faction in four places:
- Art. II §2 *Establish Proportional Voting Systems*: "the factional makeup of an
  elected body is matched to the collective preferences of the voters."
- Art. II §2 *Ensure Election Security*: "…where all factions can observe and audit…"
- Art. II §4 *Factionally Proportional Committees* + *Committee Seat Assignment
  Process*: committee seats awarded **to factions**, distributed within factions.
- Art. III §2 *Composition of Executive Committees*: "same kind of factional
  proportions."

Built reality (settled during the 2026-05 apportionment cleanup): there is **no
faction layer**. Political parties, businesses, nonprofits, CGCs are all
`organizations`; any organization OR individual endorses any candidate
(polymorphic `endorsements`); committee assignment is faction-independent
(rank-order preferences; ties broken by vote share after normalizing quotas for
one-person-one-vote deviations); proportionality is preserved by the STV election
itself, calculated at the individual level. The Feb-2026 architecture plan §Phase 3
already records this operator specification — the template text was never updated.
→ Amendment: reword the four clauses from faction-allocated to
preference-proportional / endorsement-based language. **This is the "polymorphic
changes" item for the websites.**

**T-2 — Committee tie-break refinement.** Template: ties broken by "1st choice vote
performance in the prior election." App adds quota normalization (one-person-one-vote
deviation correction) before comparing. → Minor amendment folding normalization into
the tie-break clause.

**T-3 — Apportionment procedure absent.** Template gives min 5 / max 9 / mandatory
contiguous-equal subdivision / uniform ratio (Art. II §2, §8; Art. V §3) but no sizing
or allocation procedure. Built law (operator-settled 2026-07-13): root seats = rounded
cube root of population; children split by population share over the children-sum
denominator; giant cascade (≥ ceiling+0.5 rounds nearest and locks, remainder
redistributes); drawn districts round nearest independently; exactness rule for
generated maps. → Propose an apportionment annex/procedure section (or an "amendable
procedures" companion) so the published template matches the shipped math.

**T-4 — Founding context.** Template's Art. VI covers *restoring* order, not first
founding. Built doctrine: bootstrap election board at genesis (chart sheet 6 agrees);
map v1 drawn in founder context (no human-seated board exists yet), v2+ governed.
→ Either a short founding note in the template or keep as operational doctrine —
operator's call; listed as `proposed` either way so the sites can explain founding.

**T-5 — Age of consent/majority.** Art. V §5 sets amendable default 18 (explicitly
never gating voting/standing). No schema counterpart exists. → App-side gap, add to
`constitutional_settings` when Phases L/M (contracts/economy) need it; template
unchanged.

**T-6 — Verified consistent (no action).** Cultural Institutions (Art. V §6) built;
Art. VII additional-articles amendments, union/disintermediation, judiciary
conversion, executive create/alter all live in `multi_jurisdiction_votes`;
supermajority formula; emergency powers; bicameral dual agreement; countback;
co-determination thresholds; Art. IV §5 three-path challenge with direct
`judicial_remedy` law edit; CGC public-domain IP.

---

## 3. Citation drift (internal — verify against the labeled docx before editing)

CLAUDE.md's hard-constraints table cites by article/section; the extracted text
places some values elsewhere:
- "Legislature min seats 5 — Art. II §2" → the min-5/max-9 pair appears in **Art. V §3**
  (Art. II §2 carries only the max-9 split trigger).
- "Judiciary min judges 5 / default appointed — Art. IV §1" → min-judges + 10-year
  terms live in **Art. IV §4**; the appointed path is **Art. IV §2** ("default" is app
  doctrine, not template text).

CAUTION: `Fair_Constitution_Labeled.docx` may carry its own label scheme that the
plain-paragraph extraction drops. Task: open the docx labels directly, build the
label↔section map, THEN correct CLAUDE.md citations (or confirm they follow the
label scheme). Do not churn citations before that check.

Fixed this session: CLAUDE.md's "104-form ConstitutionalEngine" → 109 canonical
forms (census below).

---

## 4. Architecture plan → rewrite outline for `CGA_Architecture_Plan_2026-07.docx`

| Section | Feb 2026 says | As-built |
|---|---|---|
| §2 Stack | Laravel 11, Meilisearch, Vue SPA + Pinia, Capacitor now | Laravel 12 + Inertia; no Meilisearch; Redis + Horizon; Matrix (Synapse+MAS) + LiveKit (K-3); Capacitor deferred (G-V1 parked); dual-stack dev convention |
| §2.3/§3 Federation | ActivityPub + custom extensions | Custom peer mesh: FF&C sync, authority flip (export bundle = seed), mesh roles & channels of trust, dual-meter consent |
| §4 DB | individuals, residency_pings, jurisdiction_boundaries, jurisdiction_settings, legislature_seats (with faction affiliation) | users, location_pings, `jurisdictions.geom` + versioned `jurisdiction_maps`, `constitutional_settings`, `legislature_members` (**faction columns removed**); 183-table flattened baseline (`database/schema/pgsql-schema.sql`, 2026-07-05) + real-dated additive migrations |
| §5 Phases 0–6 | 76-week plan | 0–5 complete + G, K-1, K-3; districting/autoscale campaign complete (951,626 legislatures, Earth 1,999 seats exact); forward plan = `docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md` (H→L+M→I→K-2→N→O→J) + the 2026-09-01 scoped cloud launch |
| §5 Phase 0 seed | Natural Earth + OSM | geoBoundaries + WorldPop + protomaps ETL; geodata pull engine plan next |
| §6 i18n/a11y | in Phase 6 | vue-i18n live (5 locales + glossary); full i18n/WCAG = Phase N |
| §6 Security | baseline list | + hash-chained audit, ballot commitment scheme, late-production credential pass (pre-launch gate) |
| §7/§8 | timeline + next steps | replace with as-built history + fleet/40-day plan |

---

## 5. Roles/forms chart → update list for the new xlsx

1. **Forms sheet +5**: F-ELB-008 Manual District Draw (R-08; founder context per the
   two-context rule) · F-SOC-001 Social Thread/Post · F-SOC-002 File Testimony in
   Hall (R-03) · F-SOC-003 Public-Square Removal — carve-out (R-19/R-20) · F-SOC-004
   Legal-Compliance Removal. Plus an aliases note: F-COM-001..004 → F-CHR-001..004,
   F-GOV-001/002 → F-BOG-001/002 (documented prefix drift, resolve in-registry).
2. **NEW Clocks sheet**: CLK-01..CLK-21 (03 emergency · 06 activation tiers · 09 BoG
   10-year · 13/14 co-determination · 20 Phase-F · 21 election finalist cutoff; pull
   names/durations from `ClockService`).
3. **Faction wording**: sheet 1 R-14 "Factional proportional selection" and the Art.
   II §4 basis cells → align with amendment T-1 once the operator settles it.
4. **Sheet 4 Phase 6 rows**: replace protocol-speak with the built mesh reality
   (FF&C, authority flip, mesh roles) + append G/K rows.
5. Optional: R-00 visitor footnote; K-1/K-3 surfaces (square, halls, Matrix rooms) as
   institution rows — operator's call whether the chart models the room layer.

Census (mechanical, 2026-07-24): registry canonical = 109 · chart = 104 (strict
subset) · alias IDs = 6 · clocks = 21 (chart 0) · roles = 30 (+R-00 UI-only).

---

## 6. `Topic_Knowledge.xlsx` — characterized (NOT an app authority)

Sheet "Knowledge": per-subject outreach inventory — webpage URL
(cosmopolitancoalition.org), long-video URL/title/length, question counts, **full
YouTube transcripts**, web copy. ~800k chars. It is the baseline of what the sites
and videos **currently claim** → the digest's site-update targets come from diffing
it against as-built reality (faction-era language expected in transcripts). Feeds
lanes 8a/8b (stale-claim audit), 9 (deck facts), 10/11 ({Subject} naming ties
directly to its inventory).

---

## 7. Fleet impact (paste-ready addenda live in the session report)

- **Lane 3**: `docs/extracted/roles_forms_chart.md` sheets 1/2/5 = the institution
  catalog (I-JUR..I-CGC) + role gates for the formula table. Chart sheet 4 = the
  creation dependency order.
- **Lane 2**: architecture plan's federation/mobile sections are superseded; Capacitor
  unbuilt → mobile out of 40-day scope; use Phase F/G mesh reality.
- **Lanes 9/10/11**: `docs/Topic_Knowledge.xlsx` = the existing content corpus
  (subjects, video URLs, transcripts) — reuse, don't reinvent.
- **Lanes 8a/8b**: expect faction-era language on the live sites; the polymorphic
  rewrite (T-1) will be the headline amendment once approved.
- **Lane 5**: Art. V §4 official-language power → carry a per-jurisdiction
  official-language concept in Phase N design.

## 8. Lane 7 pipeline from here

1. Operator reviews this inventory (T-1..T-5 dispositions especially).
2. Draft `docs/findings/PROPOSED_AMENDMENTS.md` — every item status `proposed`.
3. Draft `docs/findings/FINDINGS_DIGEST.md` + `TEMPLATE_TEXT.md` (zero-context
   readers; raw-URL consumers).
4. Produce `CGA_Architecture_Plan_2026-07.docx` + updated chart xlsx (originals
   untouched).
5. First push round on the operator's word → 8a/8b feed goes live.
