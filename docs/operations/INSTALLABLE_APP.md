# Installable app, residency location and notifications — 2026-09-21

Operator order: installation first, permission-based location for residency second,
then invitation/message/upcoming-clock notifications with permission settings.

## Delivered behavior

- `/manifest.webmanifest`, 192/512 PNG icons, home-screen metadata, same-origin
  service worker and an install affordance on `/system/app`. The footer links to
  **App & permissions** for guests and signed-in players. Identity and records
  stay in the existing account, on the same instance.
- The operator-selected purple/gold Cosmopolitan Coalition of United Earth logo
  is used for the favicon, Apple touch icon and app icons. Exported from the
  complete original Image Kit SVG (including its embedded branches), with square
  purple padding for maskable launchers. `scripts/build-app-icons.mjs` reproduces
  the raster exports from the committed vector asset without redrawing the mark.
- Network-first navigation with an offline connection-help document. Only that
  public document is cached: no authenticated pages, API responses, ballots,
  wallets, map archives or queued offline writes.
- Location is requested only by an explicit action. Residency can use a fresh
  high-accuracy fix to preview the containing place; the player confirms home.
  Existing check-in, residency and manual-map rules are preserved. App location
  opt-out is respected by both location actions. The settings-page location check
  does not send coordinates. Browser/OS denials explain how to restore permission;
  JavaScript cannot override or revoke a browser permission.
- Opt-in push subscriptions and choices per device, up to ten devices per
  account. Private-room Matrix invitations and private/encrypted message events
  are accepted only through the authenticated homeserver appservice receiver.
  Shared invitation links have no addressed recipient: their acceptance notifies
  the inviter. Unknown rooms, self messages, edits, old events and opted-out
  recipients are excluded. No private message text enters push payloads.
- Upcoming reminders cover the next scheduled public civic deadline of each
  supported type in one chosen confirmed jurisdiction, one hour or one day ahead.
  Public clock allowlist: general/special elections, meetings, emergency expiry,
  registration/finalist cutoff and civic stipend period. Private-case, residency
  threshold and infrastructure clocks are excluded. Cancelled/rescheduled timers,
  lost residency and changed preferences are rechecked before sending.
- **Send me a test notification**, disable this device and remove old-device
  controls. Signing out removes subscriptions registered in that session, keeping
  other signed-in devices. Browser/OS settings remain authoritative.

## Delivery and scale

`app:notifications` runs once per minute through the existing scheduler, outside
the simulation lanes. A PostgreSQL session advisory lock serializes overlapping
manual/scheduled dispatchers. Each pass checks at most 50 opted-in devices in a
fair oldest-check-first order, and sends at most 100 pending deliveries within a
45-second transport budget. Larger subscriber populations can take multiple
passes; this is not a planet-scale delivery-capacity benchmark.

The new active `(space_id, user_id)` membership index avoids scanning all room
memberships for each message. Identity resolution probes the existing localpart
index and checks the complete Matrix ID/domain. Clock probes use the existing
`(clock_id, jurisdiction_id)` index. Nothing scans all simulated people.

Durable outbox deduplication is per device/event. Retries back off and stop after
five attempts; a worker crash after provider acceptance can redeliver the same
tag (provider delivery is at-least-once, not exactly-once). Dead endpoints are
removed; completed outbox rows expire after seven days in bounded batches. Push
provider acceptance does not prove an OS displayed the alert. Endpoint capabilities,
browser keys and the stable VAPID private key are encrypted with `APP_KEY` in the
database. Keep the database and application key together in backups; deployments
do not rotate notification keys. Only allowlisted HTTPS browser push providers
are contacted, with redirects disabled and bounded network timeouts. Current
`aes128gcm` Web Push encryption and VAPID are supplied by `minishlink/web-push`.

## Completed internal validation

- 20 PHP tests / 164 assertions in nonce disposable PostgreSQL databases:
  subscription ownership/authentication, encryption at rest, provider allowlist,
  per-device choices, active residency, Matrix authentication and dedupe, member
  removal, stale/cancelled clocks, retries, expired subscriptions, outbox rollback,
  logout isolation, and existing resident-wallet regressions.
- Actual library transport signs and encrypts requests; simulated provider
  responses verify 2xx acceptance, redirect rejection and expired subscriptions.
- Five JavaScript tests cover fresh CSRF handling, same-origin notification
  navigation, and leaving mutations/API reads outside worker interception.
- Real isolated Chromium normal profile: zero installability errors, valid
  manifest/icons/worker, phone-width settings, actual allowed/denied geolocation,
  app opt-out, successful residency boundary lookup, offline fallback and no
  personal-page caching. Browser push registration/provider are simulated in the
  UI journey; subscription/preferences/test-outbox/disable hit real Laravel and
  disposable PostgreSQL.
- Vue components compile; PHP style and whitespace checks pass. No write tests
  ran against either installed world. Added packages have no reported advisory
  in the local Composer audit; pre-existing unrelated package advisories remain.

Physical Android/iOS notification display and OS-specific installation remain
device acceptance checks. iOS/iPadOS requires 16.4+ and a Home Screen installation
for Web Push. This PWA does not implement continuous/background location.

## Deployment

Use the existing exclusive developer-deployment lock. Build frontend assets in
isolation; prefetch the locked Composer dependencies. Apply the two additive
migrations (the room-member index uses CONCURRENTLY outside a transaction).
Install dependencies and publish hashed assets/locales with the manifest last.
Clear route cache; validate and reload nginx for manifest MIME/revalidation and
service-worker revalidation. The scheduler starts a fresh `schedule:run` process
each minute, which reads the new notification command. No simulation replay,
Horizon refresh, database/Redis restart or constitutional outcome change is needed.
Preserve all four local configuration files and `.env`.

Verify public `/system/app`, manifest MIME, icon sizes, worker headers and exact
asset hashes; verify migrations, scheduled command, live provider configuration
and service health. Do not send notifications to live users without their opt-in.
