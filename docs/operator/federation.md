# Federation identity & tuning

This box's place in the mesh: its stable identity, the URL peers reach it at, and the knobs that
tune Full-Faith-&-Credit sync. The identity values are **locked** (peers pinned them); the tuning
values are **instant** (no trust impact — safe to make in-console editable later).

## Locked — identity (do not change casually)

| Knob | Where | Notes |
|---|---|---|
| Server ID | `instance_settings.server_id` | This box's stable, Ed25519-rooted mesh identity. Generated once; never changes. Rotating it makes this box a *different* node and invalidates every signature and peer pin. |
| **Self URL** | `FEDERATION_SELF_URL` (`config/cga.php`) | The address advertised to peers at handshake and **pinned by them**. A change requires re-handshaking every peer — not a casual edit. |
| Schema version | `CGA_SCHEMA_VERSION` | Must match peers for FF&C sync to apply; a counted page from a peer on a different `constitutional_version` is refused (fail-closed). Bumped only by a migration. |

## Instant — tuning (no trust impact)

| Knob | Where | Notes |
|---|---|---|
| Federation enabled | `instance_settings.federation_enabled` | Master gate: is this box reachable on the mesh. |
| Heartbeat interval | `CGA_FEDERATION_HEARTBEAT_MINUTES` | How often the self-draining heartbeat pulls peer tails (default 5). |
| HTTP timeout | `CGA_FEDERATION_HTTP_TIMEOUT_SECONDS` | Per-request timeout for federation calls. |
| Cold-sync page size | `CGA_FEDERATION_SYNC_PAGE_SIZE` | Records per page during a backfill drain (default 500). |
| Geodata origin | `CGA_GEODATA_ORIGIN` | Where a joining node pulls the geodata seed from. Blank = the cluster host. |

## Joining a cluster (becoming a mirror)

Use the **Federation console** (`/federation`) → "Join a cluster", or the CLI mirror path. The join
admits, then pulls the host's geodata **seed** (foundation) and **drains** its audit corpus; the live
seed/drain progress is shown on the federation console (bars + ETAs). A mirror is **authoritative for
nothing** — it read-replicates the host and accepts no constitutional filings. Read-write authority
is a *separate*, governed petition (Art. V §7), never granted by joining.

## Notes

- `FEDERATION_SELF_URL` and `matrix.server_name` are the two values peers pin — treat both as locked.
- Never run `deploy.sh` (it runs `key:generate --force`, rotating `APP_KEY`) on a box whose identity
  keypair must be preserved — it would make the encrypted private key undecryptable.
