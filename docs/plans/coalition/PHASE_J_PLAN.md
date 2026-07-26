# Phase J — The Coalition as Organization: build plan

**Lane 14 · deliverable D-15 · status: PLAN, not built. Nothing in this document has been
implemented. Seeding is explicitly on hold at the operator's word — he wants to walk it first.**

Sources: `docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md` §Phase J (the charter) ·
`docs/plans/docs-recon/BUILT_INVENTORY.md` §3 Phase J (the audit) + §5b (the duplicated-rule
defect) · operator answers relayed 2026-07-26.

---

## 1. What this phase is, in one paragraph

The Coalition and its parent Foundation are real organisations that exist in the world. Phase J
models them *inside* the app as ordinary organisations, so that the app — not a bespoke website
feature — is where their structure, their board and their published work live. It is deliberately
small: three database columns, one seeding command, and a set of tests that prove the two
organisations hold no governmental power. It adds no new constitutional machinery, because it
does not need any: a nonprofit is a creature of Article I (assembly, association, expression) and
the app already implements Article I.

---

## 2. The two organisations — and the third that must NOT be built

Operator's answers, 2026-07-26, recorded verbatim in substance:

| | **Cosmopolitan Party Foundation** | **Cosmopolitan Coalition of United Earth** |
|---|---|---|
| What it is in law | **A 501(c)(3).** The legal entity — the corporation. | **A project underneath the Foundation.** *Not* a separate legal entity today. |
| Role here | The parent | The operating / authoring child |
| Website | cosmopolitanparty.org | cosmopolitancoalition.org |
| `type` | `nonprofit` | `nonprofit` |

**Cosmopolitan Coalition Action Fund** — a future 501(c)(4). **It does not exist and may never.**
Operator: *"if we ever reach a point where we have to engage in 501(c)(4) activities, then that
legal entity would be spun off or independently created… at this time, that's not necessary."*
**Do not create it. Do not reserve a row, a column, an enum value, or a slug for it.** It is
recorded here only so that a future reader knows the branch was considered and deliberately left
unbuilt. If it is ever needed it is an ordinary organisation registration at that time.

*A note on why the name question was worth asking:* the website is `cosmopolitanparty.org`, which
says "Party", while the legal entity is the "Cosmopolitan Party **Foundation**". A website name is
not a legal name. The app stores legal names; the websites keep their own names. These are stored
as separate fields (`name` vs `website_url`) so neither has to distort the other.

**The app stores no US tax category.** "501(c)(3)" appears in this plan as *context for us*, not
as data. There is no field for it and none is being added — the app models constitutional
categories, not the tax code of one country. The Foundation's `description` may describe itself in
its own words; that is expression, not classification.

---

## 3. The direction, and the one thing that cannot be undone

**Foundation is the parent. Coalition of United Earth is the child.** Confirmed by the operator.

Three things attach to the **child**, i.e. to the **Cosmopolitan Coalition of United Earth**:

1. `parent_organization_id` → points up at the Foundation.
2. `public_domain_charter` → set to **true**.
3. The library of published works — the public-domain corpus.

### ⚠ Say this in plain words, because it is irreversible

> **The permanent "everything we publish is public domain" flag is switched ON for the
> Cosmopolitan Coalition of United Earth, and it can never be switched off again.**
>
> Not by an administrator, not by a later act, not by editing a setting. One-way by design, in
> deliberate imitation of how the constitution already treats Common Good Corporations, and of
> how CC0 works in the real world. If it is ever set on the wrong organisation, the only remedy
> is to destroy and rebuild that organisation's record.

On a demo world that costs nothing — the seeder wipes and rebuilds. On the live instance it is
permanent. This is why the direction was worth one sentence of the operator's time before any
seeding runs, and it is why §9 keeps the live path separate from the demo path.

---

## 4. The honesty problem: a project is not a corporation

The operator flagged this and it is the sharpest design question in the phase.

`parent_organization_id` models the relationship fine — the Coalition is subordinate to the
Foundation, and the column says exactly that. The risk is **everything else the app does around an
organisation row**, because the app's vocabulary was built for independent entities:

| What the app does by default | Why it reads wrong for a project | What this plan does |
|---|---|---|
| Publishes an "Organization registered" public record under Art. I Economic Freedom | Reads as *a new legal entity came into being*. The Coalition did not incorporate. | The child's registration record body is written to say it is **a programme of** the Foundation, naming the parent. The record still exists — records are how the app tells the truth — but it states the relationship rather than implying independence. |
| Gives the org its own board | A project having its own governing board implies separate legal governance | **The board is provisioned on the Foundation** — the legal entity, which is the thing that actually has governance. See §7. |
| Lets an org dissolve itself (F-ORG-007) | A project does not "dissolve", it ends | No change to the code; noted so nobody reads a dissolution affordance as a legal claim. |
| Shows the org with its own `website_url`, description, agent | Accurate — projects do have sites and staff | Kept. |

**The rule I am holding to: the app must not make a claim the reality does not support.** Where the
schema forces a shape, the *words in the record* carry the qualification. Where it does not force
a shape, the shape follows reality — which is why the board goes on the Foundation.

This is flagged, not silently resolved. If the operator would rather the Coalition carry its own
board for demonstration purposes, that is his call to make and it is one line of change.

---

## 5. Schema delta — one migration, three columns

Real-dated, additive-only, applied on top of the baseline dump (CLAUDE.md's migration rule).
**Through the one-lane-at-a-time rule: I announce before running any migration on `fcd`.**

```
organizations.public_domain_charter    boolean NOT NULL DEFAULT false
cgc_ip_register.dedication_basis       varchar(24) NULL, CHECK IN ('constitutional_mandate','voluntary_charter')
org_memberships.is_public              boolean NOT NULL DEFAULT false
```

**`public_domain_charter`** — the voluntary charter flag. One-way false→true, enforced in the model
(§6). Distinct from `ip_is_public_domain`, deliberately — see §6.

**`dedication_basis`** — *why* a given register row is public domain. `constitutional_mandate` = a
CGC under Art. III §5, which the Template requires. `voluntary_charter` = an organisation that
chose it, which the Template is silent about. Nullable so every existing row stays valid without a
backfill; new rows always set it. **The distinction is the whole point:** it keeps the
constitutional mandate legible as a mandate and prevents a voluntary act from ever being mistaken
for one the constitution compelled.

**`org_memberships.is_public`** — optional per the charter; included because the Coalition's whole
purpose is public-facing membership. Defaults **false**: privacy is the default, publicity is the
opt-in, never the reverse. Membership is an Article-I association and no one is outed by a seeder.

---

## 6. The two-flag problem (a §5b duplicated-rule risk, caught before it was built)

`organizations` **already has** `ip_is_public_domain`, carrying the Art. III §5 CGC pin in
`Organization::booted()` — a CGC's flag can never flip false. This plan adds a *second* boolean
that also means "this organisation's output is public domain."

**Two columns meaning nearly the same thing is exactly the defect §5b names.** The day someone asks
"is this org's work public domain?" there will be two places to look and they can disagree.

**The resolution — one authoritative answer, asked, never restated:**

- `ip_is_public_domain` stays **exactly what it is today**: the CGC constitutional mandate. The
  Art. III §5 branch is byte-identical; nothing in this phase writes it, reads it, or reasons
  about it. This satisfies the charter's "CGC branch byte-for-byte unchanged."
- `public_domain_charter` is the voluntary charter, and applies only to non-CGCs.
- **One method on the model becomes the single authority**, and every surface, test and seeder asks
  it rather than checking either boolean:

```php
/** Why this org's output is public domain — or null if it is not. */
public function publicDomainBasis(): ?string
{
    if ($this->is_cgc) {
        return 'constitutional_mandate';   // Art. III §5 — the Template compels it
    }
    return $this->public_domain_charter ? 'voluntary_charter' : null;
}
```

This is also precisely the value `cgc_ip_register.dedication_basis` must record, so the register
row and the organisation can never drift apart: **the dedication asks the organisation, and the
organisation is the only one who knows.** Per §5b's test discipline, the pin asserts *that
agreement* — that a row's `dedication_basis` equals its organisation's `publicDomainBasis()` — not
any hardcoded string. Pin the relationship, not the number.

---

## 7. The board — and what "co-determined" honestly means at this size

The exit criterion asks for a **member-elected, co-determined board**. Both halves need care.

**Member-elected** works out of the box. A `nonprofit` structure maps to membership kind `member`
(`Organization::STRUCTURE_MEMBERSHIP_KIND`), and `OrgBoardElectionService::openOwnerElection()`
(F-ORG-003) runs the owner-track election. Members elect the board. Nothing new is needed.

**Co-determined is where the honest answer differs from the impressive one.** Art. III §6 gives
workers their first board seat at **100 employees**; `CoDeterminationService::workerSeats()` returns
**0** below that. The real Foundation does not have 100 employees.

| Option | What it shows | Verdict |
|---|---|---|
| Seed 100+ synthetic workers so worker seats appear | A visibly co-determined board | **No.** It fabricates employees a real organisation does not have, on records the operator specifically warned will be read by people who know what these words mean. |
| Seed honestly (real headcount, likely a handful or none) | 0 worker seats | Correct, but a walker sees nothing and concludes the feature is missing |
| **Seed honestly AND surface the rule** ✅ | 0 worker seats *and* the reason, plus the headcount at which the first seat appears | **This one.** |

The third is both honest and more educational than the second. The co-determination machinery is
genuinely wired and genuinely evaluated; it correctly yields zero. The seeder reports that outcome
along with the next threshold — and gets that threshold by **asking
`CoDeterminationService::nextStep()`**, never by printing the number 100. That is §5b applied: the
service owns the rule, the seeder asks it, and the display follows the rule automatically if the
setting is ever amended.

Per §4, the board is provisioned on the **Foundation** — the legal entity.

---

## 8. The public-domain dedication branch — extending a pin without weakening it

This is the phase's one genuinely delicate edit. Two separate tests constrain it.

### Pin 1 — the service's public surface is frozen at exactly one method

`CgcIpPublicDomainTest::test_the_service_exposes_dedicate_and_nothing_else` asserts
`assertSame(['dedicate'], $methods)`. **A second public method such as `dedicateVoluntary()` is
therefore impossible** — adding one turns that test red.

**Decision: widen the existing guard inside `dedicate()`. Do not add a method, do not relax the
pin.** Today:

```php
if (! $org->is_cgc) { throw new ConstitutionalViolation(
    'IP dedications belong to Common Good Corporations — this organization is not a CGC.',
    'Art. III §5'); }
```

becomes a check against the authority defined in §6: an organisation may dedicate if
`publicDomainBasis()` is non-null — i.e. it is a CGC (mandate) **or** it has adopted the charter
(voluntary). Everything else is still refused, **with the same citation and the same message for
the CGC case**, so the Art. III §5 branch is untouched in behaviour as well as in text.

**What "extend, never weaken" means concretely** — after this change all of these must still hold,
and each becomes an explicit test case:

- A non-CGC **without** a charter is still rejected, with the Art. III §5 citation. *(the existing
  contract, unchanged)*
- A CGC still dedicates exactly as before, and its rows record `constitutional_mandate`.
- A chartered nonprofit dedicates, and its rows record `voluntary_charter`.
- A CGC can never reach the voluntary branch, and a voluntary dedication can never set
  `is_cgc`, flip `ip_is_public_domain`, or otherwise be mistaken for a mandate.
- Every existing assertion in `CgcIpPublicDomainTest` still passes untouched.

*On the standing ruling that PROTECTED means live-deployment, not a development freeze:* I have
looked, and **this pin is not wrong — it is doing its job.** A one-method surface is what keeps the
register's only writer obvious. So the right move here is the one the pin was built to force:
change the behaviour *inside* the sanctioned method and extend the test to cover the new branch. I
am not requesting permission and I am not weakening anything; I am recording the reasoning because
the next reader deserves to know the alternative was considered rather than missed.

### Pin 2 — the source scan, which constrains where the seeder may live

`test_no_other_writer_or_conversion_reference_exists` walks **every PHP file under `app/`**, strips
comments, and fails if any file outside a two-file allowlist contains the literal `cgc_ip_register`
or `CgcIpRegisterEntry::`. Every `institutions:demo-*` command lives in `app/Console/Commands/` —
**inside that scanned tree.**

So the seeder must **write through `CgcIpRegisterService::dedicate()` and read through the
`$org->ipRegisterEntries()` relation**, never naming the table or the model.

This is not a workaround; it is the discipline the pin exists to enforce, and **the pattern is
already proven in-tree**: `PhaseDDemoCommand::charterCgc()` does exactly this today and says so in
a comment at that spot. My seeder copies a working precedent rather than inventing one.

---

## 9. Where these organisations actually live: demo world vs the live instance

**A finding that changes the shape of the deliverable, surfaced rather than worked around.**

Every demo command calls `GuardsSyntheticData::guardSyntheticData()`, which **refuses to run unless
the world has declared itself `instance_class = scale_demo` or `game_mode = sandbox`.** The live
earth.* Standard instance is neither. Therefore:

> **`institutions:demo-coalition` can never run on the live instance — by design, and the design is
> right.**

The guard's reasoning is sound and I am not going around it: a command that mints records no human
consented to must not touch a real world. So the two paths are genuinely different things:

**On demo / sandbox worlds — `institutions:demo-coalition --fresh`.** The chartered exit criterion.
Seeds both organisations, the board, the corpus. Walkable, teardownable, repeatable. This is what
this plan builds.

**On the live instance — no seeder at all.** The Foundation should come into being the way the
constitution says organisations come into being: **a human being exercises Article I association and
registers it**, through the real form (F-IND-012, role R-03), consenting as themselves. That is not
a limitation to engineer around — it is the constitution working exactly as written, and a seeded
"real" organisation would be a worse artifact than a registered one.

Two small things the live path needs that ordinary registration cannot do today, both already in
scope:
1. `OrgRegistryService::register()` cannot set `parent_organization_id` — the parent link must be
   set as a deliberate second step.
2. Adopting the public-domain charter is its own irreversible act (§3) and should be, not a
   checkbox smuggled into a registration form.

**Recommendation:** these two steps become a small, explicit, non-synthetic operator command
(no `GuardsSyntheticData`, because it mints nothing — it links and flags two organisations a human
already registered). Scoped, named, and separate from the demo seeder. **Flagged for the operator's
decision; not built until he says so**, since it is the only part of this plan that would ever
touch the live world.

---

## 10. The civil-society firewall — what the pins actually assert

The charter's requirement: **Article-I levers only; zero legislative, executive, judicial or CGC
power.** The good news is that the codebase already enforces this; the pins make it *provable* and
keep it true as forms are added.

The organisation's agent holds exactly one role: **R-23**. Its complete form surface in
`FormRegistry` is seven forms, all of which are an organisation running *itself*:

```
F-ORG-001  Organization Profile Management
F-ORG-002  Candidate Endorsement Grant        ← the Article-I expression lever
F-ORG-003  Board Election Administration
F-ORG-004  Worker Board Election Administration
F-ORG-005  Ownership Transfer Initiation
F-ORG-006  Public-Private Conversion Request   (shared with R-09)
F-ORG-007  Organization Dissolution
```

There is no legislative form, no executive form, no judicial form, and no route to creating a CGC
(that is `F-LEG-019`, legislature-only, and self-registration as a CGC is validator-rejected in
`OrgRegistryService::register()`).

**The pins, written as relationships so they survive new forms** (§5b test discipline — pinning the
number would go stale the first time a form is added):

1. **The firewall pin.** Derive the Coalition's and the Foundation's available forms *from
   `FormRegistry`* and assert that none of them is gated on a legislative, executive or judicial
   role. A future form handed to R-23 that crosses the line fails this test on the day it is
   written — which is the entire point.
2. **No governmental columns.** Assert both organisations have `created_by_legislature_id`,
   `overseen_by_executive_id`, `created_by_law_id` and `is_cgc` all null/false. They are civil
   society; nothing in government made them and nothing in government oversees them.
3. **Endorsement is permitted.** The firewall must not over-fire: F-ORG-002 is Article I expression
   and a nonprofit endorsing a candidate is a *right*, not a leak. Pinned positively so a later
   over-zealous tightening breaks a test.

### `parent_organization_id` — zero test coverage today, per the audit

The column, the FK and the `parent()`/`children()` relations all exist and **nothing reads or
writes them anywhere in the codebase.** Phase J is their first real use, so Phase J owes them their
first pins: the link resolves in both directions, the FK's `ON DELETE SET NULL` behaves, a child is
not confused for a root, and — the one that matters for §4 — **a child organisation gains no
authority whatsoever from having a parent.** Parenthood is a statement of relationship, not a
grant of power.

---

## 11. Explicitly NOT built here

- **The Δ4 authorship bridge** (`authored_by_organization_id`, `authored_by_user_id`,
  `ip_register_entry_id`) — **owned by K/N.** I hand over the contract shape and the two
  organisation IDs; I do not create the columns.
- **The Cosmopolitan Coalition Action Fund** — see §2. Not built, not reserved.
- **F-LEG-028 Cultural Institution Recognition** — already built (`CulturalInstitutionRecognitionVote`).
  It is the *legislature's* form (R-09), not the nonprofit's, and the registry comment already
  describes what it recognises as *powerless*. Available if the operator ever wants the Coalition
  recognised; consumed, never rebuilt.
- **`foundation_sync_cursors`** — federation seed-drain plumbing. Nothing to do with the Party
  Foundation. Untouched. (Anti-pattern-match table, BUILT_INVENTORY §5.)
- **Any US tax-category field.** See §2.

---

## 12. Build order

Each step is small and independently verifiable. Nothing starts until the operator gives the word.

1. **Migration** — the three columns of §5 (announced first, per the one-lane rule).
2. **Model layer** — `public_domain_charter` fillable/cast, the one-way false→true guard beside the
   existing Art. III §5 guard, and `publicDomainBasis()` (§6).
3. **The dedication branch** — widen the guard inside `dedicate()`; record `dedication_basis` (§8).
4. **Extend `CgcIpPublicDomainTest`** — the five cases in §8, existing assertions untouched.
5. **`institutions:demo-coalition`** — modelled on `PhaseDDemoCommand`: `--fresh` teardown, tagged
   slugs, services called never rules restated, corpus written through the service (§8 pin 2).
6. **Firewall + parent-link pins** (§10).
7. **Walk it** — the operator's walkthrough, then a screenshot of the seeded organisations per the
   standing after-a-fix proof rule.

**Exit criterion:** `institutions:demo-coalition --fresh` seeds both nonprofits at Earth
(`adm_level = 0`, the planet root) with a member-elected board on the Foundation, a public-domain
corpus on the Coalition, and green firewall pins.

**BUILT will not mean TESTED, and neither will mean WALKED.** Step 7 is not a formality.

---

## 13. Open items for the operator

| # | Item | Blocking? | My recommendation |
|---|---|---|---|
| 1 | Board on the **Foundation** (the legal entity) rather than on the Coalition (a project) — §4 | No. Built as recommended unless he says otherwise | Foundation. It is the thing that actually has governance. |
| 2 | The live-instance path: an explicit non-synthetic linking command vs. leaving the live orgs to ordinary registration — §9 | **Yes, before anything touches the live world.** Nothing in the demo path waits on it | Build the small explicit command, but only when the live instance is real. Not in this build. |
| 3 | Founding date, mission line, public contact for each organisation | No — fields stay null until he supplies them | Supply when convenient; nothing waits. |
| 4 | Whether the Coalition's records should name it a *programme of* the Foundation in so many words — §4 | No | Yes. It is what is true. |

---

*Plan authored 2026-07-26 by lane 14. Nothing built, nothing tested, no commits. Seeding held at
the operator's instruction pending his walkthrough.*
