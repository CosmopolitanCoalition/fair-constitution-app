# Setup Wizard — Field Notes (captured bugs for the integration refactor)

Companion to `SETUP_SCALING_PARADIGM_AUDIT.md`. Bugs the operator hit while
running setup live, captured for the AI that does the Step 3/4/5 wizard refactor.
Not fixed here — recorded so they are not rediscovered.

---

## FN-1 — Operator-host IP save bounces the wizard; Continue then no-ops

**Symptom (operator, 2026-08-04):** on the Operator step, entering an IP/address
for the operator host and clicking **Save** refreshes the page. From then on,
**Continue** must be clicked several times to advance — sometimes it "refreshes
and stays on that page," possibly a cache/staleness effect where the advance
doesn't render.

**Diagnosis — CORRECTED 2026-08-05 (the first pass had it wrong).** The original
note blamed Laravel's Inertia **asset-version hash**. That was a hypothesis and
it is WRONG: `HandleInertiaRequests` has **no `version()` override**, so the
asset version is the vite manifest hash, which an `.env` write does not move.
Recording the correction so it is not re-derived.

Confirmed facts (read at `6186f9e`):

- `saveProfile()` (`OperatorSetup.vue`) is a clean `csrfFetch` and does **no**
  reload — the refresh is not JS-triggered. There is **no `<form>`** around it
  either, so it is not a stray submit… except the Save button was the lone
  control missing `type="button"` (now added, defensively).
- The backend (`SetupController::operatorProfile`) writes `FEDERATION_SELF_URL`
  to **`.env`** and returns `restart_required: true` (the value needs a
  container recreate to take effect — its own message says so).
- **The environment is the suspect, not the code:** the game box serves the
  **Vite dev server** (`public/hot` present), `vite.config.js` uses
  `server.watch.usePolling` over the whole project, and **`.env` was not in the
  `ignored` list**. A poll that picks up the `.env` write is the most plausible
  trigger for a dev-mode reload.

**What was changed here (2026-08-05), both defensible on their own merits:**
1. `vite.config.js` — added `**/.env` and `**/.env.*` to the watch `ignored`
   list. HMR has no reason to watch `.env`; polling it across the Windows
   boundary is pure cost, and it removes the prime reload suspect.
2. `OperatorSetup.vue` — `type="button"` on Save (every other control had it).

**NOT yet confirmed live.** Static reading could not prove the exact reload
trigger, and two prior hypotheses were wrong — so this is hardening aimed at the
most likely cause, not a certified fix. **Confirm during the next fresh run's
operator-setup step:** watch the Save click (network + navigation + console) and
verify no full reload. If it still reloads, the trigger is elsewhere and the
live trace will show it.

**For the refactor regardless (audit §5, "every step re-enterable; long ops
off-request"):** an `.env` write mid-wizard is the anti-pattern — stage the
value in instance settings and apply it at ONE controlled restart gate (end of
setup, or a dedicated "apply network config" step) so no mid-wizard write can
ever bounce the page, in dev or prod.
