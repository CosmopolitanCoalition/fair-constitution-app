# Setup + Jurisdiction Viewer — UI punch list

Live queue for the operator's walkthrough (opened 2026-08-04). He ships fixes as
he sees them; this file is the durable record so nothing is lost between
messages. **Arrival order ≠ work order** — items are pulled in dependency order,
noted per item.

Status: `DONE` shipped · `NEXT` unblocked, queued · `BLOCKED` waiting on
something named · `DESIGN` needs a decision before code.

---

## DONE

| # | Item | Landed |
|---|---|---|
| 1 | Map health statistics — shared `lib/mapHealth.js`, per-check `?` explainers in the flag queue, framing note, actionable-first ordering | `1da122f` |
| 2 | Scan detector bars moved out of Ingestion into Review & Accept, above their own findings; `ScanDetectorBars.vue`, `scan-state` emit | `1da122f` |
| 3 | Step 2 block 1 welded: Your Map Data → Data source → Start button | `d345396` |
| 4 | Level table header row + `?` explaining populated-vs-existing | `d345396` |
| 5 | Total population = Earth roll-up (8,032,680,693), not the 32B column sum | `d345396` |
| 6 | One forward button in the Continue slot ("Accept Map Data to Continue →") | `d345396` |
| 7 | Jurisdiction Viewer link opens in a new tab (no longer drops you out of setup) | `d345396` |

---

## NEXT — unblocked

### N1. Collapsible flag categories · Jurisdiction Viewer
Each flag's DETAIL collapses, but the CATEGORY does not. At Earth level
`same-space chains` alone is 8,547 rows, so the queue is one enormous scroll.
Categories collapse to a header + count; the detail behaviour inside is
unchanged. Default-collapsed above some row count so Earth opens readable.

### N2. Statistics as sortable columns · jurisdiction list
Operator: *"if we're doing a table view like this and not the actual map view,
we need the statistics on columns on the bars so that we can sort."*
**Needs backend first:** `/api/geodata/flags` has NO jurisdiction scoping — no
subtree filter, no per-jurisdiction rollup. "Statistics for their internal
chain" requires a new endpoint that counts flags over each row's descendants
before the columns can exist. Pairs with the jurisdiction map viewer
conversation he flagged as coming next.

### N3. Setup UI inside the main shell
Operator: you authenticate for setup anyway, so bring the nav bar and menu in
and **lock out the menus that aren't relevant yet** rather than running setup as
a separate chrome-less flow. Related to B1 below — same layout surface.
Cross-cutting: touches every setup step, needs a rule for which menus unlock
when.

---

## BLOCKED — waiting on the operator's screenshots

### B1. Jurisdiction Viewer map layout
Reported 2026-08-04 against `/jurisdictions/earth-0-earth`:
- Map is **too zoomed in** and boxed — a large dead border around it instead of
  filling its area.
- **Empty void space left and right** of the content column.
- The map **does not sit behind the menus** (layer controls float over it
  awkwardly rather than the map running underneath).

He is sending screenshots to pin down exactly what he means. **Do not
restructure the map layout until they arrive** — guessing here means redoing it.

---

## DESIGN — needs a decision, not just code

### D1. "Activate now (dev)" becomes SET MAP / accept-geography
The operator's model, captured verbatim in substance:

> Accepting a geography **applies to the whole chain of jurisdictions**. All
> district maps and all government institutions that stem from those
> jurisdictions then generate from it. So as a planet, in the game context, we
> need to *agree* on it.

So the button is not a dev activation toggle — it is the **map-adoption act**,
and it is the entry point for the whole cycle:

```
upload a new dataset → review it → create the next geodata map
                                 → next wave of district maps off that geodata
```

It must live in **both the jurisdiction map maker and the jurisdiction map
viewer**, and it must keep working **outside setup mode** — re-adopting a new
geography is a standing governance act, not a one-time install step.

Open questions to settle before building:
1. What does adoption do to institutions **already elected** under the previous
   geography? Regenerate, migrate, or hold until term end?
2. Is adoption scoped (a jurisdiction adopts for its own subtree) or planetary
   only? His wording says "this chain of jurisdictions", which reads scoped.
3. Does it need a vote/supermajority in game context ("we need to agree"), or is
   it an operator act during setup and a legislative act afterward?
4. Relationship to `map_accepted_at` and `assertRepairWindowOpen()` — today
   acceptance permanently closes the repair window. A re-adoption cycle needs
   that window to REOPEN, which the current gate does not contemplate.

### D2. "About this surface" → Learn tab
Operator: the explainer block "doesn't need to exist" on the viewer; it belongs
in the Learn tab. Straightforward to remove, but confirm the content lands
somewhere in Learn rather than being deleted.

### D3. "You're viewing as a guest" banner
Operator: "that's kinda like a pop-up thing, and I guess that can be in the map
area somewhere." Currently a full-width banner eating vertical space above the
fold. Needs a placement decision — overlay chip on the map, dismissible toast,
or a corner affordance.

---

## Carried debt (not from this walkthrough)

- **Empty-ADM-level walk** — 678 nonexistent levels × ~11 s of re-listing the
  same 715-entry directory ≈ 2 h aggregate lane time, ~25 min wall clock per
  full run. Cause: `discover_geoboundaries_files()` is called once per level and
  is not memoised; `levels` is `range(6)` with no check against the metadata
  already loaded. Operator has seen it; not yet prioritised.
- **`displaced_geometry` cap** — hard-capped at 500 per scan, truncation flag
  set, so the true count is unknown. Operator ruled this *not worth the work
  right now*; recorded so it is not mistaken for a real count later.
- **No bulk merge_chain** — 11,919 flagged merges, every repair endpoint is
  one-per-POST, and the repair window shuts on acceptance.
- **Pixel screenshots** — the browser pane will not composite in this
  environment, so UI verification here is DOM-level (structure, ordering,
  rendered values) and not visual. Operator's eye is the pixel check.
