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

**Diagnosis (read from both ends at `84e787c`; one link still a hypothesis):**

- `saveProfile()` (`OperatorSetup.vue:109`) is a clean `csrfFetch` — it does no
  reload itself. It records `restartRequired = !!data.restart_required`.
- The backend (`SetupController.php:~3328`) writes the changed self-URL to
  **`.env`** (`FEDERATION_SELF_URL`) and returns `restart_required: true`,
  because an `.env` value "cannot take effect live" (its own comment, ~:3176).
- **The refresh:** an `.env`/config change moves Laravel's Inertia **asset
  version hash** (`HandleInertiaRequests::version()` tracks build/config state).
  When the next Inertia request carries a stale `X-Inertia-Version`, Inertia's
  contract is a **full hard reload** — exactly the "refresh" seen the moment
  after Save, and again on the next navigation.
- **Why Continue then no-ops:** `continueNext()` (`OperatorSetup.vue:260`) is
  **not gated on `restartRequired`** — the amber banner (`:382`) only informs.
  So Continue fires an Inertia visit into the version-mismatch / app-reload
  window: the visit either hard-reloads back onto the same step (asset-version
  bounce) or the server responds before the new state is durable, and it takes
  a couple of tries before the version handshake settles.

  *Unverified link (house rule):* whether the `.env` write also bounces PHP-FPM
  itself, or whether the asset-version hash alone accounts for it. The
  asset-version mismatch is sufficient to explain the whole symptom; a FPM
  bounce would compound it. The next AI should confirm which before choosing
  the fix.

**Fix direction (for the refactor, not built here):** an `.env` write mid-wizard
is the anti-pattern — it invalidates the running app under the operator's feet.
Options, cheapest first:
1. **Gate Continue on `restartRequired`** — when true, replace Continue with an
   explicit "Apply address & restart, then Continue" affordance that performs
   the restart (or instructs it) and only advances once the app reports healthy.
   Smallest change; makes the existing banner actionable.
2. **Stage the value, apply at a controlled point** — don't write `.env` on Save;
   hold the address in instance settings and apply it at a single restart gate
   (end of setup, or a dedicated "apply network config" step), so no mid-wizard
   reload ever happens.
3. Both — stage during the wizard (2), and keep (1) as the explicit apply gate.

**Fits the audit's §5 cross-cutting rules:** "every step is re-enterable —
returning to the wizard shows live state, never restarts" and "every long
operation runs off-request." An `.env`-triggered app reload is the sharp edge
those rules exist to sand down; this is a concrete instance for the refactor to
resolve, not a new principle.
