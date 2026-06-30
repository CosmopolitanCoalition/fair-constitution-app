# Host-apply supervisor (restart-tier changes)

Some knobs are **restart-tier**: env/yaml-baked and applied only by rewriting `.env`
and **recreating a container**. The Laravel app runs *inside* a container with no host
`.env` access and no Docker socket, so it physically cannot do this itself. Instead the
Operations console writes a **desired-state control file** and a small **host-side
supervisor** — which you run — applies it. This is the same control-file pattern as the
ETL supervisor, but it runs on the **host** (it needs the host `.env` + Docker).

## What it can change

A **strict whitelist** — today the LiveKit ICE networking knobs only:

| Env key | Validated as | Recreates |
|---|---|---|
| `LIVEKIT_NODE_IP` | an IP address | `livekit` |
| `LIVEKIT_PUBLIC_URL` | a `ws://` / `wss://` URL | `livekit` |

**No secrets** are applyable through this path — rotating API keys/secrets/tokens is
deliberately excluded until the credential-security pass (use `php artisan matrix:setup`).

## Running the supervisor

On the **host** (not in a container), from the repo root:

```bash
python3 scripts/ops/infra_supervisor.py            # watch loop (Ctrl-C to stop)
python3 scripts/ops/infra_supervisor.py --once     # apply one pending request, then exit
python3 scripts/ops/infra_supervisor.py --dry-run  # validate + print, never write/recreate
```

It needs nothing beyond Python 3 (stdlib only) and the `docker compose` CLI.

## The flow

1. On the console (Operations → "Apply restart-tier changes"), edit a value and click
   **Apply & recreate**. The app validates and writes `scripts/ops/control/request.json`.
2. The supervisor (running on the host) picks it up, **backs up `.env`** to
   `.env.bak.<timestamp>`, rewrites the whitelisted keys, and runs
   `docker compose up -d --force-recreate livekit`.
3. It writes `done.json` (or `failed.json`); the console polls and shows
   **pending → applying → applied / failed**.

If the console shows **pending — waiting for the host supervisor**, the supervisor isn't
running; start it on the host.

## Safety

- The supervisor validates every key against the same whitelist the app does (defence in
  depth) and validates each value (IP / ws-URL) before touching anything.
- `.env` is backed up before every edit — to roll back, restore the latest `.env.bak.*`
  and recreate the service.
- Only the whitelisted compose service is ever recreated.
- The control files are gitignored (runtime only).
