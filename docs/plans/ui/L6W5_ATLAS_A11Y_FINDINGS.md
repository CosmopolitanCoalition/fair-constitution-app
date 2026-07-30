# Atlas a11y review — L6W5 ③ (findings for lane 4)

**Reviewer:** lane 6 · **Target:** `resources/js/Pages/System/Atlas.vue` (+ its `atlas-*` CSS in
`resources/css/cga/components-v2.css`). **Posture:** a REVIEW, not a rebuild — I did not edit Atlas.
**Scope (my bounded a11y column):** keyboard nav, focus order, contrast, labels/alt text, touch
targets at 375. Structural/token items are flagged for Phase N, not fixed here.

**Verdict:** a strong a11y baseline. One medium finding (heading/region structure), a few low ones,
and several things lane 4 already did right. Triage as you see fit — none of these block the walk.

## Findings (ranked)

### F1 — MEDIUM · heading/region structure on the vital-signs cards
`Atlas.vue:684-690`. Each of the 9 domain cards is a `<section class="card atlas-domain">` whose
title is a `<span class="eyebrow">` — **not a heading, and the section has no `aria-labelledby`.**
So a screen-reader user gets neither a heading nor a named region to navigate the domain breakdown
("Reach & legitimacy", "Representation", "The judiciary", …). This is inconsistent with the map,
trends, CTAs and directory sections, which ARE named regions (`aria-labelledby` → their eyebrow id:
`Atlas.vue:522, 780, 819, 835`).
**Recommend:** give each domain card title a real heading — `<h3>` under the `Vital signs` `<h2>`
(`Atlas.vue:681`) — or, minimally, an `id` + `aria-labelledby` on each `<section>` to match the
other sections. Headings are the stronger fix (they add H-key navigation over the 9 domains).

### F2 — LOW · directory table headers lack `scope`
`Atlas.vue:847-854`. The 8 `<th>` in the Nodes & operators table have no `scope="col"`. The table
scrolls horizontally at 375 (`.table-wrap`), which makes column association matter more for AT.
**Recommend:** add `scope="col"` to each `<th>`.

### F3 — LOW · touch targets at 375 are borderline
The layer toggles (`.atlas-toggle`, `components-v2.css:1266` — `padding: space-1 space-2`, font
`text-xs`) and the opt-in button (`.btn--sm`, `Atlas.vue:662`) compute to roughly **24 px** tall —
at or just under the WCAG 2.2 AA 2.5.8 (Target Size, Minimum) 24×24 floor, depending on line-height.
Width is fine (both carry text).
**Recommend:** a `min-block-size: 1.5rem` (24 px) on `.atlas-toggle` / `.btn--sm` to guarantee it.

### F4 — LOW · off-state toggle legibility
`.atlas-toggle:not(.is-on)` dims to `opacity: .45` (`components-v2.css:1269`). State itself is
conveyed robustly and NOT by opacity alone — `aria-pressed` (`Atlas.vue:544`) + `line-through` carry
it — but at .45 the label text likely drops below contrast minimums and is hard to read.
**Recommend:** a gentler dim (e.g. `.6`) or muted-token color instead of raw opacity.

### F5 — INFO (reviewed, acceptable) · the map is aria-hidden by design
The map `<svg>` is `aria-hidden="true" focusable="false"` (`Atlas.vue:567`) — correct: it is
decorative orientation ("orientation, not surveillance"). Consequence: the hover `<title>` tooltips
(place/org names, node status/uptime) are not reachable by keyboard or AT. The **node** data is fully
present in the Nodes & operators table, so nothing is lost there; place/org names are not surfaced
elsewhere for AT users, but that is consistent with the design intent. **No change recommended** —
flagging only so the choice is on the record.

### F6 — FLAG → Phase N token sweep (not Atlas-specific)
Small-text tokens `--gov-fg-subtle` / `--gov-fg-muted` at `text-xs` (tile labels `Atlas.vue:702`,
gloss/citation throughout) — verify ≥ 4.5:1 against `--gov-surface`. This is an app-wide design-token
question, out of my per-screen scope; it belongs to the Phase N contrast/token sweep, not to Atlas.

## Already right (no action)
- **Reduced motion handled:** `atlas-node-pulse` + `atlas-dial-val` gated at
  `components-v2.css:1343-1346`; the live-pill `.dotlive` at `:694`.
- **`aria-pressed`** on the layer toggles; **`role="group" aria-label="Map layers"`** on the toggle
  bar (`Atlas.vue:537`).
- **`role="status"`** on the opt-in result line (`Atlas.vue:670`).
- **Sparklines** carry `role="img"` + a descriptive `aria-label` (`Atlas.vue:734-741, 796-803`); the
  reach **dial** is `aria-hidden` with its value available as adjacent text (`Atlas.vue:722`).
- **Colour is never the sole indicator** — tones ride with a text label + icon (tiles, node pills,
  trend deltas via arrow-up/down + text).
- **Place picker** `<select>` is wrapped in its `<label>` (`Atlas.vue:752-765`); focus order follows
  a logical DOM order with no traps.
