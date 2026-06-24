# World of Statecraft — mockups

Navigable static mockups of the Cosmopolitan Governance App. Two layers:

- **v1 — the operations contract** → open **`index.html`**. Every role, workflow, and form for the build team (~144 screens).
- **v2 — the game layer** → open **`v2/index.html`**. The player's view: journeys, the Live Civic Room, the economy, social, the operator plane, and Learn & support.
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

**Copy the whole `mockups/` folder.** It is fully self-contained:

- All paths are relative — nothing is hard-coded to a host or an absolute location.
- Fonts are self-hosted (`assets/fonts/`) — no Google Fonts, no CDN.
- All data is inline JavaScript — no API, no database, no network calls at render time.
- v2 lives under `mockups/v2/` and reuses `mockups/assets/` — so copy the **whole `mockups/` folder**, not just `v2/`.

That means you can drop it on a USB stick, a LAN file share, a static web host (S3, GitHub Pages, nginx, an internal wiki), or email a zip — and it just opens. Works fully offline, including on low-powered LAN-only hardware.

### Notes
- The demo controls (persona / jurisdiction / scenario / language / RTL) persist via `localStorage`; everything degrades gracefully if a browser blocks storage on `file://`.
- A few "learn more" links in the v1 pages point to `cosmopolitancoalition.org` — those (and only those) need internet when clicked; nothing else does.
