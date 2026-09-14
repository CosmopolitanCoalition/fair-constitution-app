# D1 — Final rehearsal plan (prepare-only)

*Review register row D1 · rehearsal runner. **Verdict: BLOCKED.** This document
and its runner (`scripts/demo/d1_rehearsal.mjs`, steps in
`scripts/demo/d1_steps.mjs`) are written now so the instrument exists; the
rehearsal itself does not run until the rows below unblock.*

The presenter journey below is drawn from
[`docs/plans/launch/CLOUD_REHEARSAL_RUNBOOK.md`](../plans/launch/CLOUD_REHEARSAL_RUNBOOK.md)
(Part D operator wizard walk and the empty-second-chamber note) and from the
route table in `routes/web.php` / `routes/auth.php`. Every route citation was
re-opened against the tree at authoring time; re-open before trusting a path.

---

## Verdict and the rows this waits on

**BLOCKED.** The full simulated-presenter end-to-end rehearsal cannot be claimed
on box E. It waits on exactly three rows, all deferred by operator ruling
2026-09-14:

| Row | Unblocked by | Why D1 needs it |
|---|---|---|
| **Setup · worlds** | operator GO (BENCHMARK_GO_GATE is absolute) + a seeded simulated world | No simulated world is seeded, so the world-watch pages carry no rich data and the action journey has no live election, legislature or case to act on. |
| **Mesh/rooms · TLS** | Linux host + Docker Engine + DNS + cert (Caddy / Synapse / MAS / LiveKit) | The live commons (Matrix square/halls) and voice (LiveKit) ride the public TLS overlay; without it the commons degrade to empty and rooms media is absent. |
| **Host · rollout / Host · internet** | a provisioned Linux conference host with public DNS/TLS/TURN (cloud box OFF since 2026-09-10) | The presenter drives the demo on a deployed public host over the internet; box E is a dev/test box only and no conference host is provisioned. |

Additional precondition from the campaign plan (section 4): every non-blocked
S1 predecessor review must be passing before D1 runs.

No runner existed before this pass. This pass writes the plan and a runner
skeleton and runs only its two safe modes (`--plan`, `--dry-run`) as a guest.
No action is performed on the live app.

---

## Actors

| Actor | Role | Note |
|---|---|---|
| **guest** | Unauthenticated visitor | Reads every public page. No session, no writes. The only actor the dry-run drives. |
| **presenter** | Signed-in demo operator | Registers on the beta box (instant residency, `CGA_RESIDENCY_INSTANT`), which opens a demo session. Every filing is a real write captured against the demo session and voided at logout (`DemoSessionService`, `AuthenticatedSessionController:97`). Never driven by this lane. |
| **audience** | Conference viewers | Watch the presenter. Not a software actor; present for narration only. |

### The demo session mechanism (why action steps are stubs)

On a `scale_demo` box (`config/cga.php:276` `demo_session_capture`,
`config/cga.php:283` `residency_instant`), a signed-in user's filings are real
writes tagged to a demo session and reversed at session end.
`DemoSessionService::currentId` (`app/Services/Demo/DemoSessionService.php:43`)
opens a session for a signed-in user; `endCurrent('logout')` voids it. A demo
session therefore requires a signed-in account. This lane never registers, logs
in or submits a form, so every action step is an explicit not-implemented stub
that refuses to run without a demo session.

---

## The journey, step by step

Modes: **read** — a guest-readable GET, the dry-run opens it and asserts it
renders. **auth-wall** — a page that must redirect a guest to `/login`, the
dry-run asserts the redirect. **action** — a write requiring a demo session,
never executed here.

### Phase 1 · arrival & framing (guest reads)

| Step | Mode | Route | What the presenter shows | Expected nav / refusal |
|---|---|---|---|---|
| D1-01 | read | `/` | The front door | Home cover renders for a guest; a signed-in user 302s to `/civic` (`routes/web.php:50-54`). |
| D1-02 | read | `/launchpad` | The arrival hub | Renders; public (`routes/web.php:61`). |
| D1-03 | read | `/tour` | The guided tour index | Renders; public (`routes/web.php:62`). |
| D1-04 | read | `/explore` | The role explorer | Renders; public (`routes/web.php:63`). |

### Phase 2 · watch the world (guest reads; data from Setup · worlds)

| Step | Mode | Route | What the presenter shows | Expected nav / refusal |
|---|---|---|---|---|
| D1-05 | read | `/atlas` | The whole world on one screen | Renders from the nightly `world_stats` rollup; public (`routes/web.php:357`). A withheld figure renders as a gap, never a zero. |
| D1-06 | read | `/building` | How much of the world exists | Renders counts only; public (`routes/web.php:338`). |
| D1-07 | read | `/reach` | The enrolment gauge | Renders the nightly snapshot; read-only, no lever (`routes/web.php:348`). |
| D1-08 | read | `/jurisdictions` | The jurisdictions index | Renders; public (`routes/web.php:272`). |
| D1-09 | read | `/jurisdictions/{slug}` (discovered) | A place's own page | Renders; every jurisdiction link lands on the place, not the map (`routes/web.php:326`, operator 2026-09-10). |
| D1-10 | read | `/jurisdictions/{slug}/map` (discovered) | That place's full-bleed map | Renders (`routes/web.php:327`). |
| D1-11 | read | `/federation` | Between Governments | Renders; read-only citizen view, public (`routes/web.php:757`, ruling §10 item 9). |
| D1-12 | read | `/jurisdictions/union-formation` | A lifecycle door | Read view renders; the propose door (F-LEG-029) is auth + chamber-seat gated (`routes/web.php:281, 288`). |

### Phase 3 · learn & media (guest reads)

| Step | Mode | Route | What the presenter shows | Expected nav / refusal |
|---|---|---|---|---|
| D1-13 | read | `/learn` | The education plane | Renders; reading is open to everyone, guests included (`routes/web.php:391`, §5.0.2). |
| D1-14 | read | `/learn/guides` | The learn guides | Renders; public (`routes/web.php:392`). |
| D1-15 | read | `/learn/{track}/{module}` (discovered) | One lesson | Renders; the CHECK is authed + throttled — a guest reads but cannot submit (`routes/web.php:403-410`). |
| D1-16 | read | `/videos` | The multi-track video library | Renders; public (`routes/web.php:69`). Media source is `config/cga/media.php` `CGA_MEDIA_BASE_URL`; null renders the poster placeholder. |
| D1-17 | read | `/achievements` | The achievements catalog | Renders read-only; public, the earned overlay is the signed-in viewer's own ledger (`routes/web.php:375`). |

### Phase 4 · civic proceedings & records (guest reads)

| Step | Mode | Route | What the presenter shows | Expected nav / refusal |
|---|---|---|---|---|
| D1-18 | read | `/legislatures` | The legislatures index | Renders; public read. |
| D1-19 | read | `/civic/commons/square` | The live commons square (Matrix) | Renders; public, `withoutMiddleware('auth')` (`routes/web.php:1338`). Live reads degrade to empty when the homeserver is down; a fully live commons needs Mesh/rooms · TLS. |
| D1-20 | read | `/system/public-records` | The public records | Renders; public system surface. |
| D1-21 | read | `/system/clocks` | The constitutional clocks | Renders; public system surface. |

### Phase 5 · guest-observable refusals (nav state assertable as a guest)

These are the refusals the dry-run *can* prove without a session: an auth wall
bounces a guest to `/login`.

| Step | Mode | Route | Expected refusal |
|---|---|---|---|
| D1-22 | auth-wall | `/civic` | Redirects to `/login` (`routes/web.php:1302` auth group). |
| D1-23 | auth-wall | `/elections` | Redirects to `/login` (`routes/web.php:636` auth group). |
| D1-24 | auth-wall | `/civic/residency` | Redirects to `/login` (`routes/web.php:1310` auth group). |
| D1-25 | auth-wall | `/simworld` | Redirects to `/login` (`routes/web.php:773` auth group). |

### Phase 6 · the demo session journey (ACTIONS — stubs, never executed)

Each step here requires a demo session and a form submission. All are
not-implemented stubs. They are listed so the journey is complete and so the
form IDs and expected refusals are recorded for when the blocking rows clear.

| Step | Route / form | What the presenter does | Expected result / refusal |
|---|---|---|---|
| D1-26 | `/register` · **F-IND-001** | Register on the beta box | Opens the account (F-IND-001 Individual Registration) and, on a `scale_demo` box, a demo session. **Refused here:** creating an account and typing a password is prohibited for this lane. |
| D1-27 | `/civic/residency/declare` · **F-IND-003** | Claim residency (instant) | Declare then confirm; `CGA_RESIDENCY_INSTANT` confirms at once, unlocking voting and candidacy (Art. I absolute rights). **Refused:** needs a demo session + form. |
| D1-28 | `/elections/{election}/approvals` | Cast an open-ballot approval | Approval recorded. **Refused:** needs a demo session, a seated election (Setup · worlds) + form. |
| D1-29 | `/elections/{election}/races/{race}/ballots` · **F-IND-007** | Cast a ranked (STV) ballot | Ballot committed via the two-phase open-ballot scheme. **Refused:** needs a demo session, a seated race (Setup · worlds) + form. |
| D1-30 | `/civic/petitions` · **F-IND-009** | File a petition | Petition filed. **Refused:** needs a demo session + form. |
| D1-31 | `/civic/square` · **F-SOC-001** | Post to the public square | Post recorded on the uncensorable square; open to any player. **Refused:** needs a demo session + form. |
| D1-32 | bicameral act | Attempt a bicameral act with an empty Type B chamber | Both chambers must independently agree; an empty Type B chamber correctly blocks the act until the first election seats it (runbook Part D point 2). **Refused:** needs a demo session + seated data. |
| D1-33 | `/logout` | Log out | Ends the demo session, voiding every write filed during it (`AuthenticatedSessionController:97`). **Refused:** needs an established demo session. |

---

## How to run (inside the fc_vite container only)

Node and Playwright run only inside `fc_vite`. The app answers at
`http://nginx`; the Chromium headless shell ships in the image cache.

```bash
# Print the journey (no browser, no network):
docker exec fc_vite sh -c 'cd /var/www/html && node scripts/demo/d1_rehearsal.mjs --plan'

# Guest read-only pass (opens each guest page, asserts render; asserts auth walls):
docker exec fc_vite sh -c 'cd /var/www/html && node scripts/demo/d1_rehearsal.mjs --dry-run --base http://nginx'

# With no flag the runner REFUSES: the full rehearsal is BLOCKED and prints the
# three rows above.
```

### Dry-run notes

- **Guest only.** The runner uses a fresh browser context with no credentials.
  It issues only navigations (GET); action steps are skipped.
- **Dev-asset CORS.** Box E serves the Vite dev bundle. The runner re-adds the
  `access-control-allow-origin` header to the dev server's responses so the Vue
  app boots (mirrors `tests/browser/accessibility.test.mjs`); this changes
  nothing on the live app. A built (production) bundle needs none of it.
- **Discovery.** The place page (D1-09/10) and the lesson (D1-15) resolve a real
  slug/track from the index page at run time. When no simulated world is seeded
  (Setup · worlds blocked), discovery finds nothing and those steps report
  SKIPPED rather than fail.
- **What a green dry-run proves and does not.** A green dry-run proves the guest
  read surface and the auth walls stand. It does **not** establish the presenter
  action journey, the seeded world, the live commons or the public host. Those
  are the three blocked rows and remain unproven until they clear.
