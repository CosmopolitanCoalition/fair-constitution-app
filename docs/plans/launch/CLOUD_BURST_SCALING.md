# Cloud Burst Scaling (research record, 2026-08-29)

Lane 2. Ordered by the operator 2026-08-29: research how the fresh cloud run scales up
for ingest and idles down after, within a burst budget (hundreds of dollars for hours,
not $1,000/month sustained). This document is research only. No provisioning and no
scripts were run. Prices verified 2026-08-29 via the Azure retail prices API (East US 2,
Linux). Sources at the end.

Supplements `AZURE_BRINGUP_PLAN.md` (2026-07-25). That plan's serving-stack decisions
stand. This document answers the new cost-profile question.

---

## 1. The constraint

- A full world build must run once on the cloud box: geodata download, ingest,
  planet mapping, prewarm.
- The operator will not pay ~$1,000/month sustained. A ~$500 machine for hours,
  then scaled back, is acceptable.
- Finding, stated up front: the burst is far cheaper than feared. The full build
  costs roughly **$33 to $200 total** at pay-as-you-go rates, or **$8 to $46 on
  spot**. The real recurring bill is the idle floor, **$45 to $170/month**
  depending on posture (section 6).

## 2. Scale-up is already built. It is host-derived.

The engines size themselves from the machine they wake up on. No cloud-side
autoscaler is needed to "ramp up"; renting a bigger host IS the ramp.

- Worker lanes: `HostCapacity::autoscaleWorkers()` =
  `min((cores − 0.5) / 0.86, floor((max_connections − 30) / 3))`, floor 2.
  The 0.86 busy factor and the postgres share were measured on the planet run
  (PHP ~0.55 + postgres ~0.31 cores per lane). 12-core home box: ~13 lanes.
- ETL width derives from cores; `ETL_MEM_LIMIT` defaults uncapped (the live-wall
  ruling); admission walls derive from container memory.
- Consequence: the only cloud question is how to change the host size cheaply
  and put it back. That is section 4.

## 3. Serverless re-checked 2026-08-29. The July verdict holds.

The operator recalled Cloud-Run-style worker spin-up and an exception involving
the chat protocol. Both re-verified:

- **Azure Container Apps**: ingress is HTTP and TCP only. No inbound UDP, as of
  August 2026 (Microsoft Q&A + ingress docs). LiveKit voice media needs 7882/udp.
- **Google Cloud Run**: inbound is HTTP/1.x, HTTP/2, gRPC, WebSockets only. No
  UDP, no arbitrary TCP.
- Therefore the SERVING stack (app + Matrix + voice) stays compose-on-a-VM, which
  also keeps the volunteer-node symmetry ruling intact (a cloud node runs the
  same artifact as a home box).
- Where serverless does fit, later: **Container Apps Jobs with a KEDA Redis
  scaler** can run queue-shaped worker bursts that scale to zero. The mapping
  work is Horizon queue jobs, so this is a real future path for instant-deploy
  templates. It needs an image registry, VNet wiring to postgres, and it hits
  the same postgres ceiling as everything else (section 7). Not for run one.

## 4. The burst mechanics: one VM, the resize law

Verified Azure facts:

- A deallocated VM is billed **zero compute**. Disks and the static public IP
  keep billing. Billing is per second after a 5-minute minimum.
- A VM can be **resized** to any size available in the region; deallocate first
  when the target size is not on the current hardware cluster. OS disk, data
  disks, and a static IP are unaffected. Resizing a running VM restarts it.
- **Spot**: 60 to 90 percent off, 30-second eviction notice, eviction policy
  "Deallocate" keeps the disks. Spot is set at creation time only; a spot VM can
  never become a standard VM, and a standard VM can never become spot. B-series
  cannot be spot.

So the lifecycle is:

```
create big (build)  ->  run world build at derived width  ->  deallocate
    -> resize small  ->  start (serve or idle)
```

Eviction risk on spot is absorbed by the engines' own law: every pipeline is
chunked and resumable, so an eviction costs one chunk plus a restart. This is
the ETL paradigm paying for itself.

## 5. Verified prices and derived build cost

Azure retail prices API, East US 2, Linux, 2026-08-29:

| Size | vCPU / RAM | Lanes on it* | $/h pay-go | $/h spot |
|---|---|---|---|---|
| Standard_D64as_v5 | 64 / 256 GB | 56 (conn cap) | 2.752 | 0.637 |
| Standard_D48as_v5 | 48 / 192 GB | ~55 | ~2.06 (derived, linear $/vCPU) | ~0.48 (derived) |
| Standard_D32as_v5 | 32 / 128 GB | 36 | 1.376 | 0.318 |
| Standard_D8as_v5 | 8 / 32 GB | 8 | 0.344 | 0.080 |
| Standard_D4as_v5 | 4 / 16 GB | 4 | 0.172 | 0.040 |
| Standard_B4as_v2 | 4 / 16 GB (burstable) | 4 | 0.150 | n/a |

\* Lanes = the section-2 formula at `max_connections = 200`. The connection cap
is `floor((200 − 30) / 3) = 56`. At 56 lanes × 0.86 cores the compute demand is
~48 cores, so **D48as_v5 saturates the current ceiling**; D64 buys headroom only.

Build-time bounds. Baselines are the recorded runs, never guesses:

| Phase | Baseline (12-core home box) | On 48 to 64 cores |
|---|---|---|
| Source download (~27.5 GB) | n/a (new path) | minutes to ~1 h at datacenter speed; free ingress |
| Geodata ingest | 3 h 20 m flawless (run 019fd74b, width ~10) | faster at derived width; unverified, bound 1 to 3.5 h |
| Planet mapping | ~20k legislatures/h at ~13 lanes (run 01a04ece), planet ~950k -> ~48 h | 11 h if linear at 56 lanes; unverified, bound 11 to 48 h |

Honest caveat: the 0.86 busy factor was measured at 10 lanes. Whether one
postgres instance holds the ratio at 56 lanes is unmeasured. The first cloud run
is itself the measurement; the run resumes across any mid-run resize.

**Burst totals** (build window 16 to 72 h on D48/D64):

- Pay-as-you-go: **$33 to $200**.
- Spot: **$8 to $46**, with eviction-restart friction.

Both are far under the $500 line. The $1,000/month fear does not apply to the
burst at all; it only ever applied to leaving a big box running.

## 6. The idle floor (what actually recurs)

| Posture | Compute | All-in ≈ $/mo | When |
|---|---|---|---|
| Deallocated between sessions | $0 | **~45** (256 GB P15 disk ~38 + static IP ~4 + DNS/blob ~3) | Pre-launch. Start for a session in ~2 min at $0.17/h |
| Always-on D4as_v5 | ~126 | **~170** | Once real players exist; the July "Small" tier |
| Always-on B4as_v2 | ~110 | **~154** | Alternative; burstable credit model punishes sustained load |

Disk/IP figures are the July plan's, gathered 2026-07-25; recheck in the portal.

## 7. The postgres ceiling (why one box, and what to raise)

- `docker-compose.yml:200` pins `max_connections=200` (static). That caps lanes
  at 56 planet-wide **no matter how many worker boxes exist**. Multi-box scale-out
  (VMSS, Container Apps Jobs) buys nothing until postgres itself scales. One
  D48/D64 VM reaches the ceiling alone. This is why the desk recommendation is
  one machine.
- `POSTGRES_MEM_LIMIT` defaults to 5g and `PG_SHARED_BUFFERS` to 512MB. Both are
  env-overridable with no compose edit. On a 192 to 256 GB build box these must
  be raised or postgres throttles the run. Candidate follow-up build: derive
  them from host memory at boot, the same law as everything else.
- Raising `max_connections` beyond 200 is a compose change (shared file,
  coordinate before touching).

## 8. Data movement

- Ingress to Azure is free. The box downloads sources directly; nothing bulk
  moves over the home VPN. The VPN stays available for operator access only.
- Sources and their pinned equivalents (equivalence verified, rubric
  `cloud-geodata-source`, resolved C staged): geoBoundaries at commit
  `78a697d23` (~18 GB), WorldPop R2025A v1 2023 100m constrained (~9.5 GB).
- The 126 GB protomaps basemap is display-only: Azure Blob (~$2.65/mo) or skip.

## 9. What must be built before run one (the script phase, next order)

1. **Download-to-pull path with visible progress.** Already ruled: rubric
   `cloud-geodata-source` = C (staged). The wizard's download route still points
   at the legacy seeder; the build wires download -> pull engine.
2. **Big-iron env block** in the cloud runbook: `POSTGRES_MEM_LIMIT`,
   `PG_SHARED_BUFFERS`, or the derive-at-boot follow-up (section 7).
3. **Idle-down automation**: on run completion, `az vm deallocate` -> `az vm
   resize` -> `az vm start`. Ships as a watcher in the cloud-up script plus a
   documented manual command pair. This is the whole "scale down automatically."
4. **FRESH-NODE-START-CLOUD.md burst section**: the build-size table, the
   resize-down step, the deallocate posture.

Already landed (verified in code today): conditional `key:generate`
(`deploy.sh:309`, re-run safe) and `ClockRegistrySeeder` (`deploy.sh:345`).

## 10. Decisions

Three open questions filed in the rubric, lane 2: `cloud-burst-architecture`,
`cloud-idle-posture`, `cloud-region`. Answers fold into `_ANS` as usual.

---

## Sources

- Azure retail prices API (numbers above): <https://prices.azure.com/api/retail/prices?$filter=serviceName%20eq%20%27Virtual%20Machines%27%20and%20armRegionName%20eq%20%27eastus2%27%20and%20(armSkuName%20eq%20%27Standard_D64as_v5%27%20or%20armSkuName%20eq%20%27Standard_D32as_v5%27)>
- Resize mechanics: <https://learn.microsoft.com/en-us/azure/virtual-machines/sizes/resize-vm>
- Spot VMs (discount, eviction, no conversion, B-series excluded): <https://learn.microsoft.com/en-us/azure/virtual-machines/spot-vms>
- Deallocated billing: <https://learn.microsoft.com/en-us/answers/questions/2107793/if-vm-are-deallocated-still-they-will-charge-amoun>
- Container Apps ingress (HTTP/TCP only): <https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview> and <https://learn.microsoft.com/en-us/answers/questions/5876546/can-azure-container-apps-receive-udp-packets>
- Container Apps Jobs + KEDA scaling: <https://learn.microsoft.com/en-us/azure/container-apps/scale-app>
- Cloud Run protocols: <https://github.com/ahmetb/cloud-run-faq> and <https://cloud.google.com/blog/products/serverless/cloud-run-gets-websockets-http-2-and-grpc-bidirectional-streams>
