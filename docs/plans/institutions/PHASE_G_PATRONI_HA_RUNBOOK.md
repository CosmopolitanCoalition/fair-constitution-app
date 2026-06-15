# Phase G — Patroni HA Runbook (the LEADERSHIP axis)

This is the operator-facing guide to standing up and exercising the high-availability
data tier. It is the human-in-the-loop counterpart to the app-layer `LeaderProbe`
(built + tested) and the `onOneServer()` scheduler guard.

## The one idea to keep straight

There are **two orthogonal axes**, and HA only touches one of them:

| Axis | What it means | Who decides | Where it lives |
|---|---|---|---|
| **AUTHORITY** | *which instance* owns a jurisdiction's records | a constitutional act (Phase F flip / G6 autonomy vote) | `jurisdictions.authoritative_server_id` (NULL = us) |
| **LEADERSHIP** | *which node inside "us"* currently accepts writes | **Patroni** (data tier), never PHP | `clusters.leader_server_id` / `leader_epoch` |

A Patroni follower still presents `authoritative_server_id = NULL`, so **nothing in
the authority path changes** when leadership flips. The app never votes on leadership;
it only **observes** it (`LeaderProbe`). This separation is grep-pinned
(`ClusterAuthoritySeparationTest`, `LeaderProbeTest`).

## What's in the box

- `docker-compose.ha.yml` — etcd (the DCS) + `patroni1` + `patroni2` (PostGIS nodes) + HAProxy.
- `docker/patroni/Dockerfile` + `entrypoint.sh` — Patroni on the same PostGIS 17 base the app already uses; bootstraps the app DB + PostGIS extensions (the HA equivalent of `init.sql`).
- `docker/haproxy/haproxy.cfg` — one stable write endpoint (`:5432` → current primary) + a read endpoint (`:5433` → hot standbys), health-checked against Patroni's REST API.

The app is **unchanged**: point `DB_HOST` at HAProxy and it always reaches the
current primary. `LeaderProbe::isPrimary()` reads `pg_is_in_recovery()`;
`LeaderProbe::timeline()` reads `pg_control_checkpoint().timeline_id`, which Postgres
**increments on every promotion** — so the leadership epoch advances automatically on
each failover and the monotonic fence in `reconcileLeadership` just works.

## Lab topology (the Ubuntu desktops)

A genuine failover test wants the two Patroni nodes on **separate machines** so
killing one is a real node loss. Suggested LAN layout:

- **box-A** (Ubuntu): `etcd` + `patroni1` + `haproxy`
- **box-B** (Ubuntu): `patroni2`
- **dev box**: the app stack, `DB_HOST=<box-A LAN IP>`, `DB_PORT=5440`

For a first smoke test, run the whole `docker-compose.ha.yml` on **one** box — it
proves promotion end-to-end before you split it across machines.

> Lab vs production: the compose ships **one** etcd node — fine for a failover demo
> (the DB survives a Postgres node loss). **Production needs three etcd nodes** so the
> DCS itself tolerates a node loss; a single etcd is a single point of failure for
> leader election. Split the three across three machines.

## Bring it up

```bash
# On the box that will host etcd + patroni1 + haproxy:
docker compose -f docker-compose.ha.yml up --build -d etcd haproxy patroni1
# On box-B (or same box for the smoke test):
docker compose -f docker-compose.ha.yml up --build -d patroni2

# Watch the cluster form — one Leader, one Replica:
docker exec cga_ha_patroni1 patronictl -c /etc/patroni/patroni.yml list
```

Expected:

```
+ Cluster: cga-cluster ------+---------+---------+----+-----------+
| Member   | Host     | Role    | State   | TL | Lag in MB |
+----------+----------+---------+---------+----+-----------+
| patroni1 | patroni1 | Leader  | running |  1 |           |
| patroni2 | patroni2 | Replica | running |  1 |         0 |
+----------+----------+---------+---------+----+-----------+
```

HAProxy stats (cluster health) at `http://<box>:7000/`.

## Point the app at the cluster

In the app `.env`:

```
DB_HOST=<HAProxy host>     # e.g. the box-A LAN IP, or 127.0.0.1 for the smoke test
DB_PORT=5440               # HA_PG_WRITE_PORT → HAProxy :5432 → current primary
```

Then the usual first-run: `php artisan migrate --force` and
`php artisan db:seed --class=ClockRegistrySeeder --force` (the deploy script does both).
Reads can optionally target `:5441` (the read endpoint) later; the app uses the single
write endpoint today.

## The failover sim (the human-in-the-loop test)

This is the HA analogue of the live mirror sim — verify that killing the leader
promotes the follower with **no authority change** and an **advancing leadership epoch**.

1. **Record the baseline.** With the app pointed at HAProxy:
   ```bash
   php artisan tinker --execute '
     $p = app(\App\Services\Cluster\LeaderProbe::class);
     echo "isPrimary=".var_export($p->isPrimary(),true)." timeline=".$p->timeline()."\n";
   '
   ```
   Expect `isPrimary=true timeline=1`.

2. **Reconcile the cluster's leadership from the data tier** (records who leads, at the
   current timeline epoch — the app OBSERVING Patroni):
   ```bash
   php artisan tinker --execute '
     $svc = app(\App\Services\Cluster\ClusterMembershipService::class);
     $c = \App\Models\Cluster::firstWhere("is_self", true) ?? $svc->form();
     app(\App\Services\Cluster\LeaderProbe::class)->reconcileFromDataTier($c);
     $c->refresh();
     echo "leader=".$c->leader_server_id." epoch=".$c->leader_epoch." topology=".$c->topology."\n";
   '
   ```
   Note the `epoch` (= timeline = 1).

3. **Kill the leader:**
   ```bash
   docker kill cga_ha_patroni1
   ```
   Within ~`ttl` (30s) Patroni promotes `patroni2`. Watch:
   ```bash
   docker exec cga_ha_patroni2 patronictl -c /etc/patroni/patroni.yml list
   ```
   `patroni2` is now **Leader**, **TL 2** (the timeline incremented on promotion).
   HAProxy has already repointed `:5432` to it.

4. **Confirm the app followed the leader — without touching authority:**
   ```bash
   php artisan tinker --execute '
     $p = app(\App\Services\Cluster\LeaderProbe::class);
     echo "isPrimary=".var_export($p->isPrimary(),true)." timeline=".$p->timeline()."\n";
     // authority is UNCHANGED by the failover:
     echo "authoritative rows owned by us: ".\Illuminate\Support\Facades\DB::table("jurisdictions")->whereNull("authoritative_server_id")->count()."\n";
   '
   ```
   Expect `isPrimary=true timeline=2` — the app writes to the new primary, and the
   **leadership epoch advanced** (1 → 2). The owned-jurisdiction count is unchanged:
   **leadership flipped, authority did not.**

5. **Re-reconcile** (step 2 again): the cluster's `leader_epoch` advances to 2 and
   `leader_server_id` becomes the new node. The monotonic fence guarantees a
   recovering `patroni1` can never reclaim leadership with its stale timeline-1
   observation.

6. **Heal:** bring the old leader back as a follower:
   ```bash
   docker start cga_ha_patroni1
   docker exec cga_ha_patroni2 patronictl -c /etc/patroni/patroni.yml list
   ```
   `patroni1` rejoins as **Replica** on **TL 2** (Patroni `pg_rewind`s it onto the new
   timeline). One Leader, one Replica again.

## Exactly-one-scheduler-leader

The constitutional clock sweep must fire on exactly one node even when every app node
runs `schedule:work`. Two guards, both already in code:

- **Dispatch:** `routes/console.php` marks the every-minute sweep `->onOneServer()` — a
  Redis lock (`CACHE_STORE=redis`) elects one tick-leader per minute. No PHP consensus.
- **Execution:** `EvaluateClocksJob` skips if `LeaderProbe::isPrimary()` is false, so a
  briefly-demoted node reached mid-failover never fires clocks against a read-only replica.

To verify, run two `schedule:work` processes and confirm each minute's sweep is
dispatched once (one `EvaluateClocksJob` on the Horizon queue), and that pausing the
Redis lock store stops dispatch entirely.

## Tear down

```bash
docker compose -f docker-compose.ha.yml down -v   # -v also drops the PG + etcd volumes
```

## Deferred (real multi-node only)

- `cluster_nodes` (Patroni member name ↔ app `server_id` map) so a **follower** can
  name *which peer* is the leader in `reconcileFromDataTier` (today only the primary
  self-reports — correct, but a follower observes nothing). Build when the lab runs a
  true ≥2-node cluster and we want every node's view of leadership populated.
- 3-node etcd quorum config for production DCS resilience.
