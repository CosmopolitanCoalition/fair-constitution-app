# Multibox run — the UI-driven mirror join (Box B joins Box A)

This is the **read-only-mirror join** path the multibox campaign tests: a fresh node (**Box B**)
discovers an existing node (**Box A**) on the LAN, joins, and replicates the whole game. It is
driven almost entirely from the **setup wizard UI** — the only terminal steps are a couple of
one-time `.env` values and (on a fresh donor) arming federation.

> This is the **mirror** path, distinct from the sovereign **`/federation` Discover→Handshake**
> console and the `deploy.sh --join` CLI in [FRESH-NODE-START.md](../FRESH-NODE-START.md). Use the
> steps below for a mirror that replicates a host's game.

---

## 0. One-time `.env` (both boxes, before `docker compose up`) — the only mandatory CLI

Set on **each** box, in its `.env`, to that box's **LAN-reachable** base URL:

```
FEDERATION_SELF_URL=http://<this-box's-LAN-IP>:<its-http-port>
# Box A (host)  e.g.  http://192.168.1.202:8081
# Box B (joiner) e.g. http://192.168.1.203:8080
```

Why it is mandatory on two separate machines:

- Box A advertises `accepting_joins=true` **only** when `FEDERATION_SELF_URL` is non-empty
  (`FederationDiscoveryService::describeSelf` → `accepting_joins = isSetupComplete() && !isMirror()
  && selfUrl!==''`). Unset → Box B's **Discover** list tags Box A **"not open"** and won't join it.
- Box B advertises its own callback URL when it joins, so Box A can **push live writes back** to it
  (Gate 3). Unset → the join still seeds/drains, but live replication never reaches Box B.
- The Docker default `host.docker.internal:<port>` is **not reachable from another machine** — it
  only works when both boxes are on one host. On a real LAN you must use the LAN IP.

**If you bring the box up with `deploy.sh`** (the Pi / production path), it now **auto-detects this
box's LAN IP** and writes `FEDERATION_SELF_URL=http://<LAN-IP>:<port>` for you — so a plain
`./deploy.sh` on a LAN box is reachable with nothing to set. It prints the value it chose. Override only
for an overlay/custom address: `./deploy.sh --self-url http://<addr> …` (and `bootstrap.sh` passes that
automatically for a tailnet/yggdrasil/onion transport). It falls back to `host.docker.internal` only if
no LAN IP is detectable (a single-host box). `deploy.sh` also runs `federation:init` (below) and mints
this box a fresh identity — correct for a **fresh** node, but for that reason
**never run `deploy.sh` on Box A** (it runs `key:generate --force`, which rotates `APP_KEY` and makes
Box A's existing encrypted federation keypair + credentials undecryptable).

**On a fresh Box A only, if you did NOT use `deploy.sh`:** arm the live-sync heartbeat once —
`docker compose exec app php artisan federation:init`. Without it, the mesh joins and cold-drains but
the CLK-20 heartbeat never fires, so Box A writes never propagate to Box B (Gate 3). Enabling
federation from the operator console toggle alone does **not** arm it.

Then bring each box up: `docker compose up -d`. Horizon (incl. the `long-running` supervisor the
seed+drain runs on) and the scheduler start automatically — **no** manual worker or `migrate` step.

---

## 1. Box B — stand up + reach the JOIN screen (UI)

1. `git clone …` → `docker compose up -d` → open `http://localhost:8080/setup`.
2. **Bootstrap page** — click **Apply schema updates** (runs every migration on disk, including the
   two newest) and **create the operator/founder account**. *(The account is created here, FIRST —
   not at the end.)*
3. `/setup` redirects to the **SOLO / JOIN fork** → choose **Join an existing mesh**.

## 2. Box B — discover + request join (UI)

4. On the JOIN screen click **Discover** — tick **"Also scan my local network"** and enter your
   subnet (e.g. `192.168.1.0/24`), *or* paste Box A's URL (`http://<BoxA-IP>:8081`) into **Host URL**.
   Discover surfaces Box A's descriptor; it should read **open** (not "not open" — see step 0).
5. Leave **Join key blank** → **Join the mesh**. You'll see *"Request sent — the host operator must
   approve it."*

## 3. Box A — accept the adoption (UI)

6. Sign into the **operator plane**: `http://<BoxA>/operator/login`.
7. Open **`/federation`** → the **Host adoption console** → **Pending adoption requests** → **Approve**.
   *(Approving admits a read-only mirror — authoritative for nothing.)*

## 4. Box B — watch the seed + drain finish (UI, unattended)

8. Back on Box B's JOIN page, the **live per-table progress panel** appears (cosmic → jurisdictions →
   rasters → settings → audit history) with %/ETA. It runs in the background and **auto-finalizes** to
   *Ready Player One* when it catches up. You can leave the page; it resumes.
   - If it stalls: confirm `docker compose ps` shows `fc_horizon` **running**. The **Resume the sync**
     button re-arms it (no host URL needed).
   - Seed misbehaving? Flip the transport with **no code**: operator console → `seed_transport` →
     `tarball`, or `CGA_FEDERATION_SEED_TRANSPORT=tarball` in `.env`.

**Gate 2 pass:** Box B `jurisdictions` count == Box A; identity preserved (Box B `server_id` ≠ Box A);
every jurisdiction stamped `authoritative_server_id = Box A`; `isMirror() == true`.

## 5. Gate 3 — prove live read-replication (Box A UI)

9. On **Box A**, make a **propagating** write. Use **`/civic/halls` → "File as testimony"**
   (**F-SOC-002**). ⚠️ A plain square/halls post (F-SOC-001) or a residency claim does **not** publish
   a `public_record`, so it will **not** propagate — testimony is the reliable demonstrator.
10. One heartbeat tick later it appears on **Box B** by replay. Box B's `audit_log` gains **no**
    locally-authored rows, and any write attempt on Box B is refused (read-only mirror).

---

## Gates 4–5 — expect this run to stop here (known build gaps, not misconfig)

These are **not** config flips; they are unbuilt UI/transport surfaces, correctly deferred to the
grand-master plan:

- **Gate 4 (cross-box role grant + traveling writes):** the engine resolver is already on
  (`AttestedForwardedActor` bound). Missing: a client action that originates a device-signed forwarded
  write, a browser route that forwards a home-jurisdiction act from a mirror, auto-**delivery** of an
  approved cross-box grant (delivery is CLI-only today — `mesh:role deliver`), and the fact that the
  `users` table does not replicate (a Box-A-home traveler has no Box B account). All are build work.
- **Gate 5 (Plane B live commons):** cross-node **text** is not implemented — the commons timeline
  reads the local Synapse keyed on a local `matrix_rooms` row that does not replicate, so a fresh Box B
  has no room to read. Cross-node **voice**: the `voice.sfu` capability **grant** is fully UI-wired
  (`/federation` Role Board → Establish/Request/Approve), but the LiveKit container is behind the
  opt-in `voice` profile (`docker compose --profile voice up -d livekit` + `node_ip`/`PUBLIC_URL` per
  [livekit.md](livekit.md)) and cross-node room resolution is unbuilt.

---

## What's confirmed automatic (you do NOT need to do these manually)

- **Schema** — the wizard's *Apply schema updates* runs all migrations (globs `database/migrations/`);
  no manual `php artisan migrate`.
- **Horizon long-running worker** — `fc_horizon` has no compose profile; it starts with
  `docker compose up -d` and runs the `supervisor-long-running` (timeout 0) the drain uses.
- **Seed transport** — defaults to `paginated` (visible, resumable, non-destructive).
- **`.env.example`** — ships a real `APP_KEY` + PostgreSQL creds (the old sqlite trap is fixed).
