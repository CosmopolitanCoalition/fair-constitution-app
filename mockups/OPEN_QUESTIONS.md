# CGA Mockups — OPEN QUESTIONS

Ambiguities not guessed at. Each entry names its source, the conservative reading the mockups
build, and what would change if JD answers differently. (Resolved entries stay here, struck
through with their resolution, so the log is also a record.)

1. **Honest-gap country choice.** §7 requires one jurisdiction-picker entry whose chain stops at
   adm 2. Built: **San Marino** (`smr-1-san-marino → smr-2-serravalle`, "OSM coverage sparse below
   adm 2"). Swap to any other small country on request — one fixtures entry.

2. **F-ELB-007 (countback) referenced but not catalogued.** Roles chart Sheet 3, F-LEG-036
   "Creates/Modifies" says "triggers countback (F-ELB-007)", but the Forms Catalog contains only
   F-ELB-001…006. Conservative reading: the countback is an **engine, not a form** (WF-ELE-03 calls
   it "Countback engine (hardened)") — `vacancy-countback.html` will render an engine chip, not a
   form chip, and the coverage matrix counts 103 forms. If F-ELB-007 should exist as a form, the
   chart needs a row and the registry regenerates in one step.

3. **Forms catalog count.** Build instructions say "~110 forms"; the actual Sheet 3 contains
   **103** form rows (counted from a fresh dump; series sums: IND 17 · CAN 3 · ORG 7 · ELB 6 ·
   LEG 36 · SPK 9 · CHR 4 · EXE 5 · BOG 2 · JDG 10 · ADV 4). Coverage axes use 103. Flagging only
   because "~110" might reflect planned forms not yet in the chart.

4. **Institutions count.** Sheet 2 contains **17** I-xxx rows (I-JUR … I-CGC). The sheet's row
   count (24) allows for up to ~20; the three category headers account for the difference. Built
   against 17. If institutions are missing from the chart, the registry regenerates in one step.

5. **adm-5 display label.** The build instructions' nesting table calls level 5+ "Sub-local"; the
   repo ETL's natural labels say 5 = "Township", 6 = "Neighborhood" (Plaza Midwood sits at level 5
   in the demo chain). Built per the instructions: six levels 0–5, level 5 labeled **Sub-local**.
   Logged because production UI labels will differ unless reconciled.

6. **`is_civic_active` vs `is_active`.** The instructions' provenance line names `is_civic_active`;
   the shipped migration column is `is_active`. Mockups display "civic active" per the
   instructions; the production build should reconcile the naming.

7. **en-XA placement.** §13 says to "include a pseudo-locale option" near the language-switcher
   requirements; §6 lists a pseudo-locale toggle in the demo bar. Built conservatively: **demo bar
   only** (it is a QA tool, not a product language). Trivial to add to the product switcher if
   intended.

8. **Bicameral per-kind threshold.** Art. V §3 requires both kinds of members to "agree
   independently" but names no per-kind threshold. Conservative reading (peg-quorum logic applied
   per kind): **majority of ALL serving members of each kind**, supermajority where the act type
   requires it. Will render that way in Stage 3 with an `· as implemented`-style flag; candidate
   for the ledger/redraft.

9. **Learn question counts.** `Topic_Knowledge.xlsx` has fractional question counts (≈7.35, ≈3.93)
   and duplicate subject rows ("Legislatures1", …) with blank counts that look like transcript
   segments. Stage 6's Learn surface will round counts to integers and skip the duplicate rows.

10. **R-17 advisors as a picker entry.** R-17 (Executive Advisor/Alternate) is an election
    byproduct (top-4 runners-up), not a pickable career path. Built: it appears in the launchpad
    role picker like every other role (30 of 30 assumable), with four named fictional advisors.
    Remove from the picker if that reads wrong.

11. **Leaf-giant demo data.** §7 wants a leaf giant flagged "requires manual line-drawing" in the
    NC district-mapper scenario, but no real US-scope leaf giant exists (states all have county
    subdivisions). Built honestly: the scenario shows **Fujian (China)** as an Earth-scope example,
    labeled as such, rather than inventing a fake US one. Tell me if a hypothetical US example is
    preferred over a real out-of-scope one.
