# Mobile jurisdiction map — 2026-09-21

The fixed 320px sidebar left only a strip of map on a phone. At widths up to
64rem, the explorer now starts with a full-width map and a Details button.
Details contains the existing jurisdiction overview, legislative maps,
institution links, breadcrumbs and geographic information. Back to map returns
to the same map position; Escape also restores focus to the toggle. Wider
screens retain the sidebar and map side by side.

Layer and zoom controls have 44px touch targets. The map follows available
viewport height, resizes after rotation or panel changes, and permits a
phone-width world view. Initial fitting waits when Details hides a loading map.
Clicking a child polygon and following an ancestor still opens its map route.
On this surface only, the mobile footer puts its existing links and information
inside Help & site information to leave room for the map.

## Internal validation

`node tests/browser/jurisdictionMobile.mjs` loads the real Inertia page, shared
shell and Leaflet through local Vite with intercepted bounded geography data.
It covers Chromium touch and Firefox; 320–1440px viewports, portrait/landscape,
no horizontal overflow, map/control sizes, Details and Escape focus, zoom,
child/parent navigation, desktop layout, footer links and RTL layout. It does
not access or write any installed database. English catalogs must be generated
by the normal Vite startup/build before running it.

## Deployment

Use the existing exclusive developer-deployment lock. Build assets in isolation,
fast-forward the tested release and publish hashed assets/locales with the
manifest last. Retain previous hashed files for open browser tabs. No migration,
configuration cache clear, service restart, worker change or world-data change
is required. Preserve `.env` and the four existing local configuration files.

Check the public map as a guest at phone and desktop widths, including opening
Details, following a polygon, returning to its parent map and accessing the help
links. Browser viewport tests do not establish physical-device browser chrome
or screen-reader behavior.

## Deployment completed

`886320c6a6e8505f1e34e1fa94c1e51f020fb5ae` deployed at 17:21 UTC on
September 21. The isolated production build and exact public asset hashes
passed. A fresh Chromium touch session on the public demo verified the full-width
map, Details/Back to map, overview and legislative links, help/report links,
country polygon navigation to Antarctica and return to Earth's map, and the
desktop sidebar. No page errors occurred and no live data was written.

All checked services retained their start times; `.env` and the four existing
local configuration files retained their hashes. Evidence is recorded under
`/home/cosmo/wos-step5-operations/evidence/JURISDICTION-MOBILE-20260921/` and the
remote checkpoint's `jurisdictionMobileDeployment` entry.
