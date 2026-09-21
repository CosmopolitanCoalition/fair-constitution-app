# First-tester residency and wallet fixes — 2026-09-21

The residency map sent the document's original CSRF token after Inertia login
had regenerated the session. It now uses the existing fresh-cookie helper and
its single bounded retry, with an actionable error if the session stays expired.
CSRF validation remains enabled. Firefox protection need not be disabled.
The undeclared screen also respects the existing instant-confirmation setting.

Residency confirmation now provisions the promised empty private wallet inside
its transaction. Existing confirmed residents can choose **Open my wallet** on
the wallet page. Unconfirmed users receive a residency link. Currency creation
may happen after residency; the recovery action supports that case too.
The authenticated action only addresses the current user, checks active root
residency, uses AccountService, and issues no money. User-row locking makes
concurrent provisioning return one account. Reads remain read-only, and assets
and listings still go through their existing constitutional forms.

## Validation

- 23 PHP tests / 167 assertions: guarded nonce PostgreSQL fixture, actual CSRF
  rejection/acceptance, automatic wallet on confirmation, atomic rollback,
  recovery/repeat requests, guest/unconfirmed/inactive denial, delayed currency,
  real asset registration and sale listing with zero balance; economic form and
  privacy regressions. Privacy tests inspect schema read-only.
- Three JavaScript checks: current cookie beats stale document token, one retry
  after a rejected token, persistent expiry stops with an actionable message.
- Real Firefox 146 with tracking protection enabled and cookieBehavior=5:
  ordinary login, map click with pre-login document token, immediate residency
  confirmation, automatic wallet and registered item. Separate already-confirmed
  identity opens its missing wallet and registers an item successfully.
- Two simultaneous PHP processes against the disposable PostgreSQL fixture:
  exactly one account and matching IDs from both calls.

Browser harness: `tests/browser/harness/residentFixture.php` requires
`RUN_SIM_INDEX_PG_TESTS=1`, creates only `resident_browser_<nonce>`, and records
its name under ignored `storage/framework/testing`. Serve that database alone on
local port 8099 with config/route caches disabled, `SESSION_DRIVER=file`,
`CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, and the existing instant-residency
flag. `node tests/browser/residentOnboarding.mjs` uses this dedicated origin and
the local Vite server. `residentFixture.php concurrency` checks real concurrent
account creation. Stop the fixture server, then `residentFixture.php drop`
removes only the validated nonce database. Never run these fixtures on the demo.

## Deployment

Use the exclusive `developer-deployment.lock`. Build frontend assets in an
isolated, network-disabled capped container using the release source and cached
dependencies. Publish hashed assets/locales before switching the manifest.
Fast-forward GitHub main, clear the route cache, gracefully refresh Horizon for
the residency service change. No migration, PostgreSQL/Redis restart, simulation
replay, payment backfill or setup change. Preserve existing configuration files.

After deployment verify the public page, exact built asset hashes, authenticated
wallet route and background-worker health. Users with a page already open should
reload once to receive the new JavaScript, then retry with Firefox protection on.
No live-user identity or production data is used for write testing.
