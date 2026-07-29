# Demo-mesh time coordination — design note (NOT BUILT)

*Lane 2, Wave 2 (2026-07-29). Ordered by the desk as a DESIGN note: per-node time
advances skew shared deadlines, so a demo mesh needs one coordinating node — design
now, build with the multibox work. The refusal gate that decides WHETHER a node may
advance already exists (`DevTimeControlsEnabled`, ruling 2026-07-28 §10 item 4);
this note designs WHO advances and HOW the mesh stays in step.*

## The problem, precisely

Clocks are per-node rows (`clocks`, CLK-01..21, each with its own `fires_at`).
Full Faith & Credit syncs *records* between peers — it does not sync *time*. When a
demo mesh time-travels (the ruled capability: a mesh made only of declared demo
instances may), each node advancing independently produces different `fires_at`
landscapes: node A advances 30 days and runs its election-window timers; node B,
untouched, still believes the window opens next month. Records then flow between
two nodes that disagree about *what is due*, and every downstream surface (Today
feed, clocks page, election scheduling) contradicts its peer's copy.

Single-box demos never see this. It appears exactly when the operator does the
thing demo peering exists for: a full-scale multibox playtest.

## Where skew can actually arise (this bounds the design)

| Mesh shape | Writable clocks | Skew risk |
|---|---|---|
| Host + mirrors (adoption) | The HOST only — a mirror is authoritative for nothing and the engine write-guard refuses its writes, clock advances included | **None.** The host advances; mirrors receive the records the advance produced, on trust, like any other records. |
| Sovereign demo peers (handshake) | Every node — each is authoritative for its own partition | **Real.** Two sovereigns advancing independently skew any deadline whose records cross the mesh. |

So the coordinator problem is a *sovereign-peer* problem. The adoption topology —
the shape the one-command cloud path and the Pi test both produce — is already
safe by construction, which is why nothing here blocks Wave 2.

## Design

### 1. The coordinator is the world-starting node, by default

One mesh = one game, and one node started the game. That node — the adoption host
in a host+mirror mesh, the first sovereign in a peer mesh — is the time
coordinator. No election protocol: the operator may re-designate explicitly, but
the default requires zero configuration and matches where authority already
concentrates. Designation lives in `instance_settings` (a `time_coordinator_server_id`
nullable column; NULL = "this node coordinates" mirroring the
`authoritative_server_id` idiom). **Column addition = a migration — queued behind
lane 6's and lane 13's migration slots, per the Wave 2 boundary.**

### 2. An advance is a mesh RECORD, not a mesh RPC

The coordinator's advance already produces a dry-run plan and an audited apply
(`dev:clock-advance` / the Demo flyout console). The design extends the apply to
append one signed record to the sync stream:

```
demo_time_advances { advance_id (uuid), days (int), issued_by (server_id),
                     issued_at, plan_hash }
```

Non-coordinator demo nodes apply the SAME logical advance idempotently
(`advance_id` is the dedup key) through the SAME code path the local console uses
— every guard in `DevTimeControlsEnabled` runs again on the receiving node, so a
node that has meanwhile peered with a real instance refuses the replayed advance
exactly as it refuses a local one. Riding the existing FF&C channel means no new
transport, ordinary retry/backfill semantics, and an audit-chained history of
every advance on every node.

### 3. Non-coordinator nodes refuse LOCAL advances while peered

The gate grows one clause (build-time, not now): when this node is a declared-demo
mesh member and `time_coordinator_server_id` names another node, a local advance is
refused with the coordinator named in the refusal sentence — same
refusal-sentence-verbatim contract as every other playtest control. A node alone
in the world (no peers) coordinates itself; nothing changes for single-box demos.

### 4. The escape hatch: explicitly asserted skew tolerance

Some rigs legitimately want independent time (e.g. comparing two played timelines
side by side). That is an explicit, per-mesh assertion — an operator setting, off
by default, recorded in the audit log when flipped — never an implicit fallback.
With it asserted, clause 3 stands down; nothing else changes.

## What is NOT in this design

- No new transport, no live clock-sync protocol, no NTP-like drift correction —
  demo time moves in discrete, human-ordered jumps only.
- No change to `launch:assert-clean` (unchanged by ruling §10 item 4).
- No behavior on production-classed nodes or real meshes: every path above sits
  behind the existing demo-mesh gate.

## Build order (when the multibox work lands)

1. The migration (`time_coordinator_server_id` + `demo_time_advances`) — queued
   behind lanes 6/13 per the migration-slot rule.
2. Advance-record emit on the coordinator's apply path.
3. Idempotent replay on sync receive (same gate, same engine path).
4. The local-advance refusal clause + coordinator-named refusal sentence.
5. The skew-tolerance assertion setting, audit-logged.

Prerequisite already SHIPPED (Wave 2, this lane): the handshake and the adoption
exchange both carry the signed `instance_class` + `game_mode` declarations the
demo-mesh gate reads — without which no adoption-minted mesh could ever open the
rail in the first place.
