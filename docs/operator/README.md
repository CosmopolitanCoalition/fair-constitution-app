# Operator runbooks — the infrastructure & identity plane

This is the documentation for the **Operator Operations console** (`/operator/operations`):
every infrastructure and identity knob this box runs on. It is **separate from the
constitution** — `constitutional_settings` govern the *polity* and change only by valid
legislative acts; the knobs here govern the *box* and are managed by the operator.

The console is **read-only** today (Phase 1): it shows every knob, its current value, its
**apply tier**, and live status (certificate expiry, capability-channel state). Use these
runbooks to change a knob; the live edit/apply controls land in later increments.

## Apply tiers

Every knob is one of three tiers — this is the single most important thing to understand:

| Tier | Meaning | How to change it today |
|---|---|---|
| **Instant** | The app reads it at runtime. | (Future: edit in-console.) Today: set the env/config and restart the app container. |
| **Restart** | env/yaml-baked at boot — the app cannot change it from inside its own container. | Edit `.env` (or the yaml), then **recreate the container**: `docker compose up -d --force-recreate <service>`. |
| **Locked** | Peer-pinned or identity (`server_name`, `federation_self_url`, `schema_version`, `server_id`). | **Do not change casually** — peers pinned it; a change breaks federation. Requires a re-handshake / re-issue, out of scope here. |

## Why "restart" can't be a button (yet)

The Laravel app runs *inside* a container with no Docker socket and no access to the host
`.env`. It physically cannot rewrite its own env or recreate its own container. The planned
**host-apply supervisor** (reusing the ETL supervisor's control-file pattern) is a small
host-side process that watches a control directory, rewrites `.env`/yaml, and runs
`docker compose --force-recreate`. Until it ships, restart-tier changes are manual (these
runbooks).

## Secrets

Secret values (API keys, tokens, the OIDC client secret) are **never shown** in the console —
only whether they are *set* and whether they are still the shared `cga_dev_*` placeholder.
Rotating secrets safely (write-only entry, encrypted at rest) is gated on the
**credential-security pass**; until then, rotate via `php artisan matrix:setup` (Matrix/LiveKit)
or by editing `.env` + recreate.

## The runbooks

- [tls.md](tls.md) — TLS certificates (ACME / Let's Encrypt, the broker, cert expiry).
- [dns-broker.md](dns-broker.md) — DNS records, the Cloudflare credential, broker channels.
- [livekit.md](livekit.md) — the LiveKit voice/video SFU and its ICE networking.
- [matrix.md](matrix.md) — the Matrix homeserver, appservice, and MAS/OIDC bridge.
- [federation.md](federation.md) — federation identity, self-URL, and sync tuning.
- [host-apply.md](host-apply.md) — the host-side supervisor that applies restart-tier changes.
