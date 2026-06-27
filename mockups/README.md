# World of Statecraft — mockups

Navigable static mockups of the Cosmopolitan Governance App. Two **versions**, each a complete, self-contained tree:

- **v1 — the operations contract** → open **`index.html`**. Every role, workflow, and form for the build team (~144 screens). The prior version.
- **v2 — the complete successor** → open **`v2/index.html`**. v2 is **a full copy of v1 *plus* the game layer** — journeys, the Live Civic Room, the economy, social, the operator plane, Learn & support — all in one self-standing tree. It contains its own copy of every v1 asset and page (so the operations screens it deep-links into live at `v2/electoral/…`, `v2/legislature/…`, etc., and the v1 operations index is `v2/operations.html`).
  - **New here? Take the [guided tour](v2/tour.html)** — one linear path through the whole thing, with a **Back / Next** bar on every screen.

## Viewing them

These are plain HTML/CSS/JavaScript. **No build step, no server, no internet required.**

- **Simplest:** double-click `index.html` (or `v2/index.html`) to open it in any browser (`file://`).
- **Or serve locally** (nicer URLs, avoids a couple of `file://` quirks):
  ```
  python -m http.server 8901 -d mockups
  ```
  then visit `http://localhost:8901/` (v1) or `http://localhost:8901/v2/` (v2).

## Dropping them somewhere else

Each version stands on its own — **copy just the version you want:**

- **`mockups/v2/`** — the complete current version (v1 + the game layer). Drop this one folder anywhere and it works fully; it has zero dependency on its parent.
- **`mockups/`** (v1 only) — the prior version, also self-contained.

Both are fully self-contained:

- All paths are relative and resolve **inside the version's own folder** — nothing reaches up to `../` or to a host/absolute location.
- Fonts are self-hosted (`assets/fonts/`) — no Google Fonts, no CDN.
- All data is inline JavaScript — no API, no database, no network calls at render time.

That means you can drop a version on a USB stick, a LAN file share, a static web host (S3, GitHub Pages, nginx, an internal wiki), or email a zip — and it just opens. Works fully offline, including on low-powered LAN-only hardware.

### Notes
- The demo controls (persona / jurisdiction / scenario / language / RTL) persist via `localStorage`; everything degrades gracefully if a browser blocks storage on `file://`.
- A few "learn more" links in the v1 pages point to `cosmopolitancoalition.org` — those (and only those) need internet when clicked; nothing else does.
