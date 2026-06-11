# CGA Mockups — OPEN QUESTIONS

Ambiguities not guessed at. Resolved entries stay here with their resolution (JD, 2026-06-11),
so the log is also a record. Only #12 currently awaits an answer (elaboration provided).

1. ~~**Honest-gap country choice.**~~ **Resolved.** Depth honestly varies by country and the demo
   now leads with it: the production dataset is ADM 0–6, ~1M jurisdictions, and the **US chain
   itself ends at the county level** — so the default demo chain is Earth → United States →
   New York → New York County (Manhattan), with Kings/Queens/Bronx/Richmond as siblings and San
   Marino as the second country. Dual-footprint jurisdictions (disputed territories) carry both
   chains and residents belong to both; all jurisdictions are viewable by all — physical-presence
   mechanics bind conduct, not visibility.

2. ~~**F-ELB-007 (countback) referenced but not catalogued.**~~ **Resolved.** The countback engine
   was faction-dependent; with polymorphic endorsements the faction part is meaningless, so
   countback runs **universally** (re-run prior ballots with the vacated member removed, no
   faction filtering) — and that is a constitutional question for the next draft. Now ledger
   entry [#q6](shared/constitutional-questions.html#q6). Countback remains an engine, not a form.

3. ~~**Forms catalog count.**~~ **Resolved.** The flowchart was made after the forms by different
   sessions; counts are not material. Built from what the chart contains (103); specific
   substantive conflicts get raised individually.

4. ~~**Institutions count.**~~ **Resolved.** Same as #3 — build from what the chart contains (17).

5. ~~**adm-5 display label.**~~ **Resolved.** Numeric ADM levels are development vocabulary and
   never display; the UI uses the ETL repo's natural labels (Planet / Country / State / Province /
   County / Municipality / Township / Neighborhood). Demo chain re-centered on New York
   (see #1); Plaza Midwood retired as the example.

6. ~~**`is_civic_active` vs `is_active`.**~~ **Resolved.** Database-layer concern for the
   production phase; mockups stay as built.

7. ~~**en-XA placement.**~~ **Resolved.** The switcher exists to satisfy the localization
   requirement — the production app will carry stored strings per language to correct
   machine-translation error. en-XA stays a demo-bar QA tool; not a product language.

8. ~~**Bicameral per-kind threshold.**~~ **Resolved as implemented; text resolution still open
   (by design).** Intent confirmed: dual agreement preserves the equal-parts and
   population-proportional parts over existing nation-state boundaries — each kind needs its own
   quorum and its own majority. Rendered as majority of ALL serving members of each kind
   (supermajority where the act type requires it). Promoted to ledger
   [#q7](shared/constitutional-questions.html#q7) for the redraft.

9. ~~**Learn question counts.**~~ **Resolved.** Reference material from earlier sessions; not
   material. Built as rendered.

10. ~~**R-17 advisors in the role picker.**~~ **Resolved.** Not a pickable path. Advisors are the
    top-4 runners-up derived by sequential exclusion (re-run the count without the winner,
    repeatedly → the ranked top five). The launchpad card is now informational ("conferred
    automatically"); the role stays assumable via the mockup controls so coverage holds.

11. ~~**Leaf-giant demo data.**~~ **Resolved.** "Giant"/"leaf" are programming vocabulary and never
    display; user-facing copy says "exceeds the seat ceiling — subdivides further" / "no child
    subdivisions in the dataset — lines must be drawn manually". Districts build from real
    jurisdictional lines wherever possible — itself a candidate rule for the next draft (folded
    into ledger [#q4](shared/constitutional-questions.html#q4)). The mapper is informational here;
    the real one is already built in the product.

12. **Co-determination interpolation lacks a ledger anchor — AWAITING ANSWER (elaboration
    requested).** Art. III §6 says worker representation "scales uniformly" from the first seat
    at 100 employees to parity at 2,000 — but no formula. The screens implement a concrete one
    (linear interpolation: worker seats ≈ round((workers − 100) ÷ 1,900 × owner seats), min 1 —
    Bluefin at 740 workers → 3 of 9-owner board) and honestly cite `Art. III §6 · as implemented`.
    Every other such citation links to a ledger entry; this one has none. Question: should the
    interpolation formula become ledger entry #q8 (a candidate for the next draft, like the
    countback)? One paragraph to add if yes.

13. ~~**Monopoly-acquisition vote threshold.**~~ **Resolved.** Unstated thresholds are ordinary
    majorities (of all serving, peg-quorum basis); supermajority applies only where stated.
    Rendered accordingly.

14. ~~**Governor-removal threshold.**~~ **Resolved.** Hiring-and-firing — ordinary majority.
    Super/non-super are switches in the constitutional mechanism layer (the code system, later
    phase). Rendered accordingly.

15. ~~**CLK-06 critical-population default.**~~ **Resolved.** Activation pegs the count of active
    players against real-world population per jurisdiction; institutions go live wherever enough
    players actually play (a county can activate before its state; Earth-wide mechanisms can
    activate first). Setup charters the final structure; play grows into it. Illustrative value
    stays on the bootstrap page; framing added there.

16. ~~**Hardened bounds for amendable settings.**~~ **Resolved.** Founding values are set on the
    setup wizard's constitutional-defaults page; afterwards hardening flows through the roles &
    permissions system (e.g. district mapping sits with election board members) and amendability
    is played for — the data-structure phase after these mockups puts it to the test. Rule-text
    bounds stay as rendered.

17. ~~**Election sub-states vs the frozen scenario enum.**~~ **Resolved.** There is no real recount
    mechanism — the count is in-system; certification is part of the regular process and a
    "recount" is an audit review (re-run tabulation, re-verify the chain). Copy adjusted on the
    results/board surfaces; page-local sub-states stay. Clock activation/lockstep context recorded
    on the term-sync page: jurisdictions activate independently as player thresholds hit, and
    election-day harmonization is an encompassing-level normalization that arrives with
    participation.
