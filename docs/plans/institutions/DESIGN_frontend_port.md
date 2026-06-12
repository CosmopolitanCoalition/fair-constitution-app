# FRONTEND DESIGN-SYSTEM PORT + APP SHELL — Architecture Section

Sources read: `mockups/assets/css/colors_and_type.css`, `mockup.css`, `fonts.css`; `mockups/assets/js/shell.js`, `demo-state.js`, `i18n.js`, `icons.js`; `mockups/MANIFEST.md` (§3, §4, §7, §9); `mockups/manifest.json` (144 records); current app `resources/css/app.css`, `resources/js/app.js`, `Layouts/AppLayout.vue`, Pages tree.

Key verified fact up front: **the mockups have no light/dark split to reconcile.** `mockup.css` paints the governance register (dark, `--gov-bg` = gray-950) on every product surface; the `--brand-*` light tokens exist only for the launchpad hero. The current app is also dark gray-950, and `--cc-gray-*` was deliberately authored to match Tailwind's oklch gray ramp. The port is therefore a *token formalization*, not a re-theme — visual regression risk on existing pages is near zero.

---

## A) TOKEN STRATEGY

### A1. Recommendation: CSS-variable bridge as source of truth + thin `@theme inline` mapping (hybrid)

Do **not** transcribe tokens into Tailwind `@theme` as the primary store. Keep the mockup token files verbatim (byte-diffable against `mockups/` so the mockup QA instruments — contrast walker, qa_scan — keep applying), and expose only the semantic tier to Tailwind utilities.

New CSS structure:

```
resources/css/
  app.css                     # orchestrator (below)
  cga/tokens.css              # = colors_and_type.css, verbatim port (1 edit: @import path)
  cga/fonts.css               # = mockups fonts.css with Vite-relative URLs
  cga/components.css          # = mockup.css sections 0–3, 5–7 (see A4 deletions)
  cga/dev-bar.css             # = mockup.css demo-bar rules, renamed .dev-bar (dev-only chrome)
```

`app.css` becomes:

```css
@import 'tailwindcss' source(none);
@source '../js/**/*.vue';
@source '../js/**/*.js';
@source '../views/**/*.blade.php';
/* …existing @source lines unchanged… */

@import './cga/fonts.css'      layer(base);
@import './cga/tokens.css'     layer(base);        /* :root custom props — layer is cosmetic */
@import './cga/components.css' layer(components);  /* CRITICAL: under utilities */
@import './cga/dev-bar.css'    layer(components);

@theme inline {
  --font-sans: var(--font-sans);          /* already Instrument Sans; now self-hosted */
  --font-display: var(--font-display);
  --font-mono: var(--font-mono);
  /* semantic governance colors → bg-gov-surface, text-gov-fg-muted, border-gov-border … */
  --color-gov-bg: var(--gov-bg);
  --color-gov-surface: var(--gov-surface);
  --color-gov-surface-2: var(--gov-surface-2);
  --color-gov-border: var(--gov-border);
  --color-gov-border-strong: var(--gov-border-strong);
  --color-gov-fg: var(--gov-fg);
  --color-gov-fg-strong: var(--gov-fg-strong);
  --color-gov-fg-muted: var(--gov-fg-muted);
  --color-gov-fg-subtle: var(--gov-fg-subtle);
  --color-gov-primary: var(--gov-primary);
  --color-gov-link: var(--gov-link);
  --color-status-success: var(--status-success);
  --color-status-warning: var(--status-warning);
  --color-status-danger: var(--status-danger);
  --color-status-info: var(--status-info);
  --color-adm-0: var(--adm-0); /* … --color-adm-5, --color-adm-0-fg … */
  --color-gold: var(--cc-gold-400);
}
```

Why this split wins:
- **Cascade-layer ordering is the migration mechanism.** Tailwind v4 emits `@layer theme, base, components, utilities`. Importing the CGA component classes into `layer(components)` means every existing `bg-gray-900 px-4` utility on the 3 developed tools still beats `.card`/`.app-shell` rules. Old pages keep rendering pixel-identical until they're deliberately migrated; new pages use `.card`, `.btn--primary`, `.field` directly.
- **`@theme inline`** (not plain `@theme`) is required because the values are `var()` references to runtime custom properties — `inline` makes utilities emit the reference so per-instance theming (the documented purple↔blue `--gov-primary` swap per instance) works without rebuilds.
- One source of truth for the WCAG-measured values (`--gov-fg-subtle` color-mix, `.proposed-flag` gold-500) — those fixes were *measured*, never re-derive them.

### A2. Self-hosted fonts

- Copy `mockups/assets/fonts/*.woff2` (14 files, ~256 KB) + `OFL.txt` → **`resources/fonts/`**, and port `fonts.css` with `url('../../fonts/…')`.
- **Recommend Vite-managed (`resources/fonts/`) over `public/fonts/`**: Laravel-Vite hashes the woff2 files (immutable caching, manifest-tracked), `font-display: swap` + `unicode-range` subsetting carry over untouched, and the LAN/offline requirement is met identically (no CDN either way). `public/` is the fallback if blade emails or non-Vite surfaces ever need the fonts; not a Phase A concern.
- Delete the `--font-sans` literal in the current `@theme` block — `tokens.css` now owns it (and adds `--font-display`/`--font-mono`, which the current app lacks).

### A3. Dark posture reconciliation

- **Single theme in Phase A: governance dark.** No toggle, no light mode. This matches both the mockups and the shipped app.
- Port the three a11y media blocks verbatim: `prefers-contrast: more` (ramp step-up), `forced-colors: active` (data-fill preservation), `prefers-reduced-motion`.
- The brand register (`--brand-*`, `.launchpad-hero`, `.kosmopolites`) ports with the tokens but is used only via a `register-brand` page-level class — reserve it for the public welcome/login and Learn surfaces. Do not build a brand-register layout in Phase A; auth pages may use the purple hero block inside the standard shell-less auth layout.
- The existing map-label CSS in `app.css` (`.jurisdiction-name-label`, Wong pop/member labels) stays as-is but should swap its raw hexes for `var(--wong-orange)` / `var(--wong-skyblue)` during the re-skin pass (F).

### A4. `--adm-0..5` tier tokens + what NOT to port

- Port the `--adm-N` / `--adm-N-fg` block (top of `mockup.css`) into `tokens.css`, and **adopt the MANIFEST §2 rename recommendation at port time**: make `--adm-0..5` the canonical definitions (direct hex/oklch values) and keep `--tier-planetary` etc. as deprecated aliases pointing *at* `--adm-N` (inverted from the mockup). Components and Tailwind mapping use only `--adm-N`. There is no supranational tier in the product; stop encoding one.
- Port from `mockup.css`: §0 ADM, §1 BASE (focus ring, skip-link, `.icon`, `.visually-hidden`, touch targets, scroll-padding), §2 SHELL, §3 COMPONENTS, §4 LAUNCHPAD (small, keep for auth/learn), §5 MOTION, §6 RESPONSIVE, §7 CONTRAST/FORCED-COLORS.
- **Do not port:** `.coverage-table`/`.coverage-cell` (mockup QA tooling), `.swatch` (styleguide-only — move to the styleguide page's scoped styles), `.dev-slot` (handoff metadata; the real tools mount there). `.demo-bar` rules move to `dev-bar.css` with class rename `.demo-bar → .dev-bar` (same styles; it becomes the dev impersonation bar, C5).
- **Preflight interaction:** `components.css` §1 BASE re-declares `body`, headings, links. In `layer(components)` these override Tailwind preflight (base layer) as intended — that's the desired global re-skin and is the *one* visible global change (body bg goes from utility-driven to token-driven; identical color).

### A5. Icons

- **Adopt `lucide-vue-next`** (npm, tree-shaken, OFL-equivalent license, no CDN — installed via `docker compose exec vite npm install lucide-vue-next`), exactly the substitution `icons.js` flags. Wrap in `Components/Ui/Icon.vue`:
  - Props: `name: string` (the 38 kebab names from `icons.js` — keep that vocabulary as the app contract), `size?: 'sm'|'base'`, `label?: string` (sets `role="img"` + `aria-label`, else `aria-hidden`).
  - Applies `.icon` / `.icon--sm` classes (CSS contract preserved) and `.icon--directional` for the frozen directional list (`chevron-right/left`, `arrow-right`, `external-link`, `book-open`) so the `[dir="rtl"]` flip keeps working.
  - Internal map `{ 'file-text': FileText, … }` — unknown names fall back to `Info` (mirrors shell.js behavior).

---

## B) COMPONENT LIBRARY

Conventions: all SFCs under `resources/js/Components/`, namespaced folders, imported with folder-qualified names (satisfies multiword rule). Components are *thin wrappers over the ported CSS classes* — no scoped re-styling; the CSS file remains the single visual source. Status/tone props are validated string enums matching the CSS modifier vocabulary.

Priority key: **P0** = shell itself · **P1** = Phase A auth + civic slice · **P2** = Phase A stretch (audit viewer, re-skin) · **P3** = later phases (build when their screens land).

| # | Pri | Component (path under `Components/`) | Encapsulates (mockup classes) | Props / slots contract |
|---|---|---|---|---|
| 1 | P0 | `Shell/AppHeader.vue` | `.app-header .wordmark .header-spacer .global-search .role-badge .avatar` | no props; reads shared props (`auth`, `jurisdiction`); slots: `actions` |
| 2 | P0 | `Shell/JurisdictionSwitcher.vue` | `.jur-switcher .cosmic-prefix .adm-sep .popover-panel .tier-dot--N` | `current: {id,name,admLevel,slug}`, `chain: Jur[]`, `cosmicPrefix: string`; emits `switch(jurisdictionId)`; panel lists chain + siblings/children fetched lazily (951k rows — search-driven, never the full list) |
| 3 | P0 | `Ui/AdmChip.vue` | `.adm-chip--0..5 .tier-dot--0..5` | `level: 0..5` (clamped), `label: string`, `dotOnly?: bool`, `title` defaults to natural level label (Planet/Country/State/County/Municipality/Township — never numeric) |
| 4 | P0 | `Shell/AppSidebar.vue` | `.sidebar .sidebar-section .sidebar-title .sidebar-link[aria-current] .sidebar-link--disabled .prereq-hint .sidebar-toggle` | `nav: NavSection[]` (C2), `roles: string[]`, `currentNavId: string` |
| 5 | P0 | `Shell/AppFooter.vue` | `.app-footer .footer-citation .footer-instance .audit-chip` | `citation?: string`, `instance: {host, authoritativeFor}`, `auditSeq?: number` |
| 6 | P0 | `Ui/Icon.vue` | `.icon .icon--sm .icon--directional` | see A5 |
| 7 | P0 | `Ui/Btn.vue` | `.btn` + `--primary/secondary/ghost/gold/danger/sm`, `[aria-pressed]` | `variant?: enum`, `size?: 'sm'`, `as?: 'button'\|'a'\|Link`, `disabled`, `pressed?: bool`, `icon?: string`; default slot |
| 8 | P0 | `Ui/Banner.vue` | `.banner--info/warning/emergency/demo .banner-title` | `tone: enum`, `title?: string`, `icon?: string`, `role`: status/alert auto by tone; default slot |
| 9 | P1 | `Ui/Card.vue` | `.card .card--inset .card-title` + `button.card` | `inset?: bool`, `as?: 'div'\|'section'\|'button'`, `eyebrow?: string`, `title?: string`; slots: default, `title` |
| 10 | P1 | `Ui/Field.vue` | `.field .field-label .field-input .field-hint .field-error .field--invalid .select` | `label: string`, `hint?: string`, `error?: string` (wire to Inertia `form.errors.x`), `id` auto, `required?`; slot: `control` (scoped: `{id, invalid, describedBy}`) wrapping `<input class="field-input">`/`<select class="select">`/textarea; companions `Ui/CheckboxField.vue`, `Ui/RadioGroup.vue` (`.checkbox .radio .radio-group`) |
| 11 | P1 | `Ui/StatusBadge.vue` | `.badge--success/warning/danger/info/neutral` | `tone: enum`, `icon?: string`; default slot (label — never color-only, label required) |
| 12 | P1 | `Ui/CitationLine.vue` | `.citation .gloss` | `text: string`, `implemented?: bool` (appends "as implemented" link → ledger page), `anchor?: string` |
| 13 | P1 | `Ui/FormChip.vue` + `Ui/EngineChip.vue` | `.form-chip .form-id` / `.engine-chip` | FormChip: `formId: string` (canonical), `name?: string` (resolved from registry if omitted), `alias?: string` — renders **name first, ID second** per MANIFEST §1 |
| 14 | P1 | `Ui/StateStrip.vue` | `.state-strip .state-node--current .state-arrow` | `states: string[]`, `current?: string` |
| 15 | P1 | `Ui/LifecycleTracker.vue` | `.lifecycle .lifecycle-stage--done/--current` | `stages: string[]`, `current: string` (stages before current get `--done`) |
| 16 | P1 | `Surface/AboutSurface.vue` | `.about-surface .about-surface-body` | `citation?: string`; slots: default (workflows/entities prose); part of D |
| 17 | P1 | `Ui/HardenedChip.vue` / `Ui/AmendableSetting.vue` | `.hardened` / `.amendable .amendable-value .amendable-meta` | Hardened: slot label, lock icon, fixed title "Hardened · protected by the constitutional test suite"; Amendable: `value`, `settingKey`, `citation?`, `default?` |
| 18 | P1 | `Ui/ThresholdMeter.vue` | `.meter .meter-fill(--met) .meter-threshold .meter-caption .meter-block` | `value: n`, `max: n` (the explicit denominator — ALL serving members), `threshold?: n`, `label?: string`, `met` computed |
| 19 | P1 | `Ui/Stat.vue` | `.stat .stat-number .stat-label .stat--accent` | `value: string\|number`, `label: string`, `accent?: bool` |
| 20 | P1 | `Ui/Avatar.vue` | `.avatar` | `initials: string`, `title?` |
| 21 | P2 | `Ui/DataTable.vue` | `.table .table-wrap .table th/td .mono` | `columns: [{key,label,mono?,align?}]`, `rows: object[]`; scoped cell slots `#cell-{key}`; always renders the `.table-wrap` scroll container (the WCAG 1.4.10 fix is structural here, not a runtime wrapper) |
| 22 | P2 | `Ui/FilterBar.vue` + `Ui/ChipToggle.vue` + `Ui/TagChip.vue` | `.filter-bar .chip-toggle[aria-pressed] .tag-chip` | ChipToggle: `pressed: bool` (v-model), slot label |
| 23 | P2 | `Ui/LogRow.vue` | `.log-row .log-seq .log-hash .log-row--rejected` | `seq`, `hash`, `rejected?`, slot body — audit-chain viewer |
| 24 | P2 | `SetupStepper.vue` (exists — reconcile) | `.stepper .stepper-step--done/--active` | keep current props, swap markup to ported classes |
| 25 | P2 | `Ui/OrgChip.vue` / `Ui/PersonaChip.vue` | `.org-chip .org-type` / `.persona-chip .persona-roles` | OrgChip: `name`, `orgType`; PersonaChip: dev-bar only in Phase A |
| 26 | P3 | `Electoral/CandidateRow.vue`, `Electoral/ApproveSwitch.vue` (`.switch`), `Electoral/RankList.vue` (`.rank-list/.rank-item/.rank-controls`), `Electoral/StvRound.vue` (`.stv-*`), `Electoral/BallotReceipt.vue` (`.receipt`), `Ui/FinalistLine.vue` | Phase B (elections engine) |
| 27 | P3 | `Legislature/SeatMap.vue` (`.seat-map .seat-dot--vacant/--speaker`), `Ui/LawDiff.vue` (`.law-diff del/ins`), `Surface/FlowStepper.vue` (`.flow-steps` family) | Phase C+ |
| 28 | P3 | `Ui/AchievementToast.vue` (`.toast--achievement .proposed-flag`) | flagged *proposed* — do not build until gamification layer is approved |

Phase A build order within the table: 1–8 (shell), then 9–20 (civic slice), 21–25 opportunistic.

---

## C) APP SHELL

### C1. Layout architecture

New **`Layouts/AppShell.vue`** (built alongside the existing minimal `AppLayout.vue`; the old one is deleted at the end of F, not mutated mid-flight):

- Renders the ported grid: `div.app-shell` → `AppHeader` / `AppSidebar` / banners region / `main#main.main-content` (slot) / `AppFooter` / `DevBar` (dev only). Skip-link first child; persistent polite live region (`#cga-live`, exposed via `useAnnounce()` composable replacing `CGA.shell.announce`).
- **Registered as the Inertia default persistent layout** in `app.js` resolve():
  ```js
  resolve: async (name) => {
    const page = await resolvePageComponent(...);
    page.default.layout ??= AppShell;   // pages may override or set null (auth)
    return page;
  }
  ```
  Persistent layout = header/sidebar/map-adjacent state survive client-side visits (important for the Leaflet tools).
- Layout props via page `defineOptions({ layout: ... })` wrapper or, simpler, a per-page shared `surface` prop drives the variants:
  - `main--wide` (96rem) and a **new `main--flush` variant** (no padding, no max-width, `display:flex; flex-direction:column; min-block-size:0; overflow:hidden`) for the three map/wizard tools — the mockups' `.dev-slot` pages assumed `main--wide`; the running Leaflet tools additionally need the full-height flex the current AppLayout gives them. `main--flush` is the only net-new CSS rule this plan adds.
  - Banners region sits inside the grid between header and main (own row), rendering `SchemaUpdateBanner` (existing, folded in) plus shared-prop-driven constitutional banners (`banner--emergency` when emergency powers active — wired in the phase that ships it; region exists from day one).

### C2. NAV object → Inertia translation

Port shell.js's `NAV` array nearly verbatim to **`resources/js/Navigation/nav.js`** (single source of truth, client-side static):

```js
export const NAV = [
  { key: 'home', titleKey: 'nav.home', visibility: 'all', items: [
    { id: 'civic-home', labelKey: 'nav.civicHome', icon: 'home', href: '/civic', phase: 'A' },
    { id: 'my-record',  labelKey: 'nav.myRecord',  icon: 'file-text', href: '/civic/record', phase: 'A' },
    ...
  ]},
  { key: 'legislature', titleKey: 'nav.legislature', visibility: 'role',
    roles: ['R-09','R-10','R-11','R-12','R-13','R-29'], items: [
      { id: 'bills', labelKey: 'nav.bills', icon: 'file-text', href: '/legislature/bills',
        enabledRoles: [...], prereq: 'R-09', phase: 'C' },
  ...]
```

Differences from the mockup:
- `rel:` html file → `href:` Laravel route path (literal paths in Phase A; adopt Ziggy only if/when route params proliferate — not required now).
- `isBuilt()` (manifest lookup) → **`phase:`** field checked against a shared prop `app.phasesLive: ['A']`. Unbuilt items render exactly like the mockup's planned state: disabled `.sidebar-link--disabled` + `planned-flag` "Planned · Phase C". This keeps the full constitutional sitemap visible from day one — the mockup's strongest navigation idea — without dead links.
- Role gating logic is a direct port of `renderSidebar()`: section hidden unless `visibility:'all'` or persona roles intersect `section.roles`; item disabled with `.prereq-hint` ("Requires R-09 · Legislature Member") unless roles intersect `enabledRoles`.

### C3. Role-awareness ← real role derivation

`app/Http/Middleware/HandleInertiaRequests.php` shares (lazy closures, cached per request):

```php
'auth' => [
  'user'  => fn() => $user ? ['id','name','email','locale'] : null,
  'roles' => fn() => $user ? app(RoleDerivationService::class)->rolesFor($user) : ['R-00'],
       // ['R-01'] → +R-02 (verified) → +R-03 (associated) → +R-04 (voter); Art. I — no other gates
],
'jurisdiction' => fn() => [ 'current' => ..., 'chain' => [...ancestors, each {id,name,admLevel,slug}],
                            'cosmicPrefix' => CosmicAddress::prefixString() /* "… · Solar System · Earth" */ ],
'instance' => fn() => ['host' => ..., 'authoritativeFor' => ..., 'auditSeq' => AuditLog::latestSeq()],
'impersonation' => fn() => ['active' => session()->has('impersonate'), 'realUser' => ...],
'app' => ['phasesLive' => ['A'], 'setupComplete' => SetupState::complete()],
'locale' => fn() => app()->getLocale(),
'flash' => ...,
```

Sidebar/header consume `usePage().props.auth.roles` — the **same R-xx vocabulary** as the mockup, but derived server-side from real residency state, never client-settable. `jurisdiction.current` = the user's primary confirmed-residency jurisdiction (deepest), with the switcher changing a *view* context (session-persisted `context_jurisdiction_id`), not the residency.

Also: kill the current `fetch('/api/setup/state')` in AppLayout — `app.setupComplete` shared prop replaces it (removes a request per page and the flash of nav).

### C4. Header composition

`AppHeader.vue` = wordmark (emblem + "World of Statecraft" / instance name from `instance`) · `JurisdictionSwitcher` (cosmic prefix `​.cosmic-prefix` + ancestor `AdmChip` chain + chevron; panel = chain + type-ahead search hitting the existing jurisdictions search endpoint — never enumerate 951k rows) · spacer · global search (Phase A: jurisdiction search only, labeled as such) · notifications popover (Phase A: empty-state stub, the region and `.notif-dot` contract exist) · locale `<select>` (C6) · auth area: logged-out → Log in / Register `Btn`s; logged-in → `.role-badge` (Avatar + name + highest role `R-04 · Voter` as `.citation`) opening a popover with My record / Log out. Popovers stay native `<details class="popover">` (keyboard-free wins) with the Escape/outside-click behavior ported into a small `usePopover` directive.

### C5. Dev impersonation bar (demo bar successor)

`Shell/DevBar.vue`, rendered only when `config('app.debug') && !app()->isProduction()` (server decides; shared prop `devBar: true`). Reuses `.dev-bar` CSS (gold-dashed purple — visually non-product, collapsible `<details>` exactly as mocked). Controls, mapped from demo-state.js:

| Mockup control | Phase A dev-bar equivalent |
|---|---|
| Persona select | **User impersonation**: `POST /dev/impersonate {user_id}` (guarded route, dev env only, audited); seed users at each role stage (R-01 fresh, R-02 verified, R-03 associated, R-04 voter) |
| Role select | **Display only** — roles are always derived; the bar shows the derivation chain, never forces a role |
| Jurisdiction select | sets the session view-context jurisdiction |
| Scenario flags | dropped in Phase A (no election machinery yet); the frozen vocabulary returns in Phase B as seeded-scenario fixtures, not client flags |
| RTL flip / pseudo-locale | kept — client-side: `dir` override on `<html>`, `en-XA` pseudo via vue-i18n postTranslation hook (port the `pseudo()` accent/pad/bracket function from i18n.js verbatim, including the ID-token skip regex) |
| Reset | `DELETE /dev/impersonate` + clear context |

When impersonating, the bar shows "Impersonating {name} — return to {realUser}".

### C6. i18n posture — **recommend vue-i18n now, chrome-only**

Install `vue-i18n@11` (vite container). Rationale: retrofitting `t()` through a growing component library is the expensive part; the mockup already proved the key vocabulary (~90 chrome keys in i18n.js `en`), and the pseudo-locale QA tool comes along nearly free.

- `resources/js/i18n/en.json` = key-for-key port of the i18n.js `en` dict (plus the es/ar/zh-Hans/hi stubs, fallback `en`).
- Scope discipline: **chrome + shared components only** (nav, header, footer, field labels in shared components). Page body copy ships literal English in Phase A — same posture as the mockups.
- `<html lang dir>` set server-side from user locale (blade `app.blade.php`), mirrored client-side on locale switch; RTL correctness already carried by the logical-properties CSS + `Icon.vue` directional flips — zero RTL overrides to write.
- Locale persisted on `users.locale`; guests get session locale.

### C7. Incremental adoption / slot strategy (MANIFEST §7)

The three developed tools keep their nav placement slots: `jurisdiction-browser` → `Jurisdictions/Show.vue`, `district-mapper` → `Legislature/Show.vue`, `setup-wizard` → Setup steps — each page declares its `surface.nav` id so `aria-current` lands on the right sidebar item. They adopt `AppShell` with `main--flush` and inherit header/sidebar/footer; their internal panes are untouched in the first pass (see F).

---

## D) PAGE SCAFFOLD CONVENTION (CGA_PAGE → Inertia)

### D1. The `surface` prop (server-side contract)

Every Inertia page receives a `surface` prop — the production successor of `window.CGA_PAGE`, assembled by a `SurfaceMeta` support class from a server-side registry (`config/cga/surfaces.php` + a `FormRegistry` built from the canonical forms chart, including the §1 alias table):

```php
Inertia::render('Civic/Residency', [
  'surface' => SurfaceMeta::for('civic/residency'),
  // → { id: 'civic/residency', title: 'Residency', module: 'civic', nav: 'civic-home',
  //     roles: ['R-01','R-02','R-03'], workflows: ['WF-CIV-02'],
  //     forms: [{ id:'F-IND-003', name:'Residency Declaration', alias:null,
  //               availableTo:['R-01'], citation:'Art. I (Right to Reside)' }, …],
  //     clocks: ['CLK-05'],
  //     citation: 'Residency verified → all associations → rights unlocked · Art. I; Art. V §1 · CLK-05' }
  ...pageData,
]);
```

Why server-side: form names/aliases/citations must come from the same canonical registry the constitutional engine validates against (one alias table, used by both validation middleware and UI), and the registry doubles as the QA cross-check (every surface id must exist in the registry — the production analog of shell.js's `crossCheckManifest()` console warning, enforced as a test instead).

### D2. `Surface/PageScaffold.vue` — the per-screen wrapper

```vue
<PageScaffold :surface="surface" :wide="false">
  <template #intro>One-paragraph .page-intro</template>
  …content cards…
</PageScaffold>
```

Renders, in order: Inertia `<Head :title>`, eyebrow (module label) + `h1` (exactly one), optional intro, **`AboutSurface` panel** (collapsed `<details class="about-surface">`: workflows list with WF-IDs, entity state machines named, `CitationLine` at the bottom — content from `surface` + an optional `#about` slot for prose), then the default slot. It also `provide()`s the surface so `AppFooter` picks up `surface.citation` for the `.footer-citation` line and `AppSidebar` gets `surface.nav` — pages never wire the footer or aria-current manually.

### D3. `Surface/FormCard.vue` — the canonical form pattern

The signature mockup pattern (name first, ID second, available-to, citation, alias):

```vue
<FormCard :form="surface.forms.find(f => f.id === 'F-IND-003')" :inertia-form="form" @submit="...">
  <Field label="Jurisdiction" :error="form.errors.jurisdiction_id">…</Field>
</FormCard>
```

Renders `Card` → `h2` = form *name* + `FormChip` (ID, alias shown as `· catalog: F-IND-00x` when drifted), availability/citation line (`available to R-01 Individual · Art. I`), default slot for `Field`s, submit `Btn` bound to the Inertia `useForm` (`processing` → disabled). Server validation errors flow into `Field.error` automatically. **Every state-changing form in the app goes through a FormCard with a canonical F-ID** — this is what makes the constitutional-engine middleware auditable from the UI (the form ID travels in the request payload as `form_id`, asserted server-side).

### D4. State machines on screen

- `StateStrip` (full machine, current node highlighted) for entity-detail surfaces; `LifecycleTracker` (compact horizontal) for list rows / dashboards.
- Machine definitions are **PHP-owned** (`config/cga/state_machines.php`, the status-column spec from DATA-MODEL_REGISTRY) and exposed per page in the entity payload: `{ status: 'ping_monitoring', machine: ['declared','ping_monitoring','threshold_met','verified','active'] }` — UI never hardcodes state lists, so a machine change is one-file.
- `AmendableSetting` renders any clock/threshold the page cites (`30 days · residency_confirmation_days · amendable`), `HardenedChip` marks hardened values — both fed from `constitutional_settings` payloads, never literals.

---

## E) PHASE A SCREEN LIST

**New pages (build in this order):**

| # | Vue page (`resources/js/Pages/…`) | Mockup source | Manifest record / roles / forms | Notes |
|---|---|---|---|---|
| 1 | `Auth/Register.vue`, `Auth/Login.vue` | `civic/onboarding.html` step 1 (account) | `civic/onboarding` · R-01 · F-IND-001/002 | No-shell layout (or brand-register hero); session auth; F-IND-001 Account Creation, F-IND-002 Terms acknowledgment as FormCards |
| 2 | `Civic/Onboarding.vue` | `civic/onboarding.html` | WF-CIV-01 · Individual state-strip | 3-step stepper (account ✓ → identity → residency) routing into 3 and 4; uses `SetupStepper` classes |
| 3 | `Civic/IdentityVerification.vue` | `civic/identity-verification.html` | R-01 · F-IND-004 | Phase A: manual/simulated verification path → R-02 |
| 4 | `Civic/Residency.vue` | `civic/residency.html` | R-01/02/03 · F-IND-003/005/006 · CLK-05 · WF-CIV-02 | The Phase A flagship: declaration FormCard, manual/simulated ping submission (F-IND-005), ping map (Leaflet, real PostGIS — replaces the mockup's static SVG), CLK-05 progress meter (`ThresholdMeter`, 22/30 days), Residency-Claim `StateStrip`, the six-AdmChip rights-chain moment on confirmation (point-in-polygon associations), hardened rights-unlock chip |
| 5 | `Civic/Home.vue` | `civic/civic-home.html` | R-03/04/05 dashboard | Stats, residency status card, "what you can do now" role-gated cards; becomes the post-login landing |
| 6 | `Civic/MyRecord.vue` | `civic/my-record.html` | R-03/04 | Roles held + derivation chain, residency history, associations table (`DataTable`), audit pointers |
| 7 | `Civic/Learn.vue` *(stretch)* | `civic/learn.html` | R-01 | Static content; brand-register accents |
| 8 | `System/AuditChain.vue` *(stretch)* | `system/audit-chain.html` | — | Read-only `LogRow` chain viewer — the cheapest UI validation of the Phase A append-only audit log deliverable |

**Re-skin slots (existing pages adopt the shell — see F):** `Jurisdictions/Index.vue` & `Jurisdictions/Show.vue` (slot `jurisdiction-browser`), `Legislature/Show.vue` (slot `district-mapper`), `Setup/Step0–4 + Bootstrap` (slot `setup-wizard`), `Home.vue` (becomes redirect: guests → login/welcome, users → Civic/Home).

**Shell deliverables counted as screens:** `AppShell` + nav with the full planned sitemap (every later-phase item visible as "Planned · Phase …"), DevBar impersonation.

---

## F) MIGRATION PATH FOR EXISTING PAGES

Sequenced for zero-regression; each step is independently shippable:

1. **Step zero:** fast-forward sync with main (24 mockup-only commits; no conflicts).
2. **Land the CSS substrate (no behavioral change):** `cga/tokens.css`, `fonts.css` + woff2 files, `components.css` in `layer(components)`, `@theme inline` mapping. Because `cc-gray` ≡ Tailwind gray and both apps are gray-950 dark, the only visible deltas are: body font becomes self-hosted Instrument Sans (already the declared family — now it actually loads), the gold focus ring, and link color. Smoke-check the 3 tools at this point — utilities still win over component classes by layer order.
3. **Build `Layouts/AppShell.vue` + shell components alongside the old `AppLayout.vue`.** Register as Inertia default layout but ship while *every existing page still explicitly imports old AppLayout* (explicit wins over default — nothing moves yet). Add shared props middleware (auth scaffolding can land with `roles: ['R-00']` for guests before the residency service is finished).
4. **Migrate Setup steps first** (lowest risk: they already pass `hide-nav`, i.e. want minimal chrome). Swap `AppLayout :hide-nav` → `AppShell` with `chrome="minimal"` (header + footer, no sidebar while `!app.setupComplete` — which the sidebar enforces anyway, so this is mostly prop renaming). Re-skin `SetupStepper` to `.stepper` classes. Their inner panels keep current Tailwind styling; opportunistically swap obvious raw colors to `bg-gov-surface` etc.
5. **Migrate `Jurisdictions/Show.vue` and `Legislature/Show.vue`** to `AppShell` + `main--flush`. Both currently rely on AppLayout's `h-screen overflow-hidden flex-col` + `main.flex-1 overflow-y-auto` for Leaflet sizing — `main--flush` reproduces exactly that contract inside the grid (this is the single highest regression risk in the whole port; verify map sizing, the mapper's drill-down, and tile prewarm flows manually after the swap). First pass changes **only the wrapper**; the 4700-line mapper's internals are explicitly out of scope. Second pass (time-permitting): swap their headers/badges/buttons to `Btn`/`StatusBadge`/`AdmChip`, and the map-label hexes to `--wong-*` vars.
6. **Migrate `Jurisdictions/Index.vue` + `Home.vue`**, fold `SchemaUpdateBanner` into the shell banners region, then **delete the old `AppLayout.vue`** and remove the `/api/setup/state` client fetch.
7. **Guardrails:** (a) Playwright smoke per tool (load, map renders, one interaction) run before/after steps 4–6; (b) a Vitest/PHPUnit check that every routed page has a `SurfaceMeta` registry entry (the `crossCheckManifest` successor); (c) the mockup `tools/qa_scan.py` hex/physical-property scan adapted to run over `resources/css/cga/` + new `Components/` to keep the zero-hex, logical-properties-only contract enforced in CI.

Risk register: `main--flush` Leaflet sizing (step 5, manual verify); preflight-vs-base body overrides (step 2, visual diff the tools); font FOUT on first paint (mitigated by `font-display: swap` + preload of the two latin regular/semibold files in `app.blade.php`); vue-i18n bundle cost (negligible at chrome-only scope).