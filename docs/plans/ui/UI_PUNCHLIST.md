# UI punchlist — findings held for after the walk

**Lane 6 · 2026-07-26.** Real findings that are **deliberately not** being fixed before the
operator's walkthrough. Each is either too broad to verify in the time available, or a judgement
that belongs to someone else. Recorded so they survive the session rather than being remembered.

Ranked by player-facing impact. Nothing here is blocking the walk.

---

## 1. "Emergency" means two different things, in 44 places

`tone="emergency"` is used for **two unrelated ideas**:

| Meaning | Count |
|---|---|
| *"Your action was refused by the constitution"* | **37** (`constitutionError` banners) |
| *"This place is under emergency powers"* | **7**, including `Civic/Home.vue:143`, cited `Art. II §7 · CLK-03` |

**Emergency powers is a named constitutional concept in this app**, with its own article, its own
90-day ceiling and its own clock. Using the same red treatment for "you tried to overdraw your
wallet" spends a word that means something specific.

The refusal case is also over-strong on its own terms: **a refusal is the constitution working**,
and dressing it as an alarm teaches the opposite of what the app is trying to teach — the same
point made on worksheet cards E-6, G-2 and I-1b.

**Why not now:** 37 sites, all on paths that lead somewhere. Changing only the economy's four
would make it inconsistent with the other 33, which is worse than the current state.
**Proposed:** a third tone for "lawfully refused", distinct from both info and emergency, applied
to `constitutionError` everywhere in one pass. *Raised by lane 13, who was right to be uneasy and
right to follow the existing idiom rather than break it locally.*

---

## 2. Shell chrome takes the top fifth of a phone screen

At 375px, the instance name, jurisdiction switcher, language selector and user chip consume roughly
the first fifth of the viewport before any page title appears. Confirmed independently in lane 9's
captures — real, in-flow, not a capture artifact.

**Why not now:** shell layout change, affects every page, needs a full re-shoot to verify.

---

## 3. Eight Vue warnings on every page load

`AppShellV2`'s `JurisdictionSwitcher` passes `level: undefined` into `AdmChip`, which declares it
as a required Number. Harmless today — **but it is console noise on every screen, and it will mask
a real error during the walk.** Anyone debugging a genuine problem sees eight red herrings first.

**Why not now:** trivial fix, but it's shell-wide and unverified at both widths; and the walk is
better served by a stable shell than a last-minute one.

---

## 4. Two app shells still coexist

`Layouts/AppShell.vue` and `Layouts/AppShellV2.vue`. **68 of 87 pages** opt into V2; 19 do not.
Consolidation candidate flagged in the built inventory.

**Why not now:** flagged to @operator as requiring a decision, not a patch. Nineteen pages is a
migration, not a cleanup.

---

## 5. The public ledger is 50 rows on a phone

After the reflow fix (`c7c2da2`), `/economy/treasury` at 375 is **6,133 CSS px** — down from 8,667,
and its ratio against its own desktop height is now 1.71× (inside the 1.5–1.9× family of its five
siblings). **The reflow defect is gone.** What remains is honest length: fifty single-line ledger
rows.

**Why not now:** progressive disclosure on mobile is a design change, not a bug fix. A public
ledger being long is defensible; it is a ledger.

---

## 6. Form chips read oddly as an accessible name — and it's systemic, not local

`Surface/FormCard.vue:62-64` renders the `FormChip` **inside the `<h2>`**, so the heading's
accessible name concatenates the form's title and its code: *"Send money Funds Transfer
F-IND-023"*. Visually the chip separates cleanly; it is the announced name that runs together.

**Scope: the shared pattern, not one page.** `FormCard` is used by **32 pages**, and there are
**82** `FormChip` uses across `Pages/`. Lane 13 raised it against their own economy headings and
offered to take it — **it isn't theirs.** They copied `FormCard`'s pattern, correctly.

**Why not now:** verbose, not wrong — a screen reader announces the form's name *and* its id, which
is over-full rather than incorrect. Fixing it means changing a heading pattern on 32 pages and
re-verifying them, which is not a day-before-the-walk change. **Proposed:** keep the chip in the
heading visually, exclude it from the accessible name.

---

## 7. Citations now speak in two registers — WATCH FOR THIS ON THE WALK

The suffix that marks *"the engine's reading of the article, not the article's literal text"* has
been softened where a player meets it, but not everywhere:

| Where | Reads |
|---|---|
| Refusals (the live render path) | *"Art. II §2, **as this app applies it**"* |
| 18 hardcoded captions | *"Art. I · **as implemented**"* |

Both are honest; they are not the same voice, and a reader hitting both in one session may notice
the seam. Lane 7 ruled this **punchlist rather than a cross-lane change on a hunch**, and the
reasoning is worth preserving: **a real reader hitting both is better evidence than three lanes
guessing.** If it jars during the walk, that's a finding worth having. If it doesn't, a change
nobody needed was avoided.

**Action: nothing before the walk.** Watch, then decide.

---

## 8. Economy surfaces are not registered

`config/cga/surfaces.php` has no economy entries, so the economy pages cannot use `FormCard` (it
wants a `SurfaceMeta` record) and get no About panel or footer citation. Lane 13 correctly avoided
inventing entries in a tree that isn't theirs.

**Why not now:** registering surfaces has knock-on effects on nav, About panels and citations.
Doing that the day before a walk is how a working screen acquires a new failure mode.

---

## Closed today, listed so they aren't re-found

- **Reflow: tables crushed instead of scrolling** — `c7c2da2`, verified by measurement and by eye.
- **`Quantity 1.000000`** — `668ba13`, and a second instance in the new asset table, `dd3d5b1`.
- **Raw `[POLICY]` token in citations** — `668ba13`.
- **Achievements rendering as dotted i18n keys** — `22c1948`, with a hyphen bug found by testing
  the fallback rather than trusting it.
- **No way to change persona with a mouse** — `5b5815a` / `a5d82d8` / `dd3d5b1`, proven by a full
  round trip: became another person, came back.
