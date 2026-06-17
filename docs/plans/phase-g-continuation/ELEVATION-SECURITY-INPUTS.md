# Operator Vision — Elevation, HA, Adversarial Resistance & Mesh SSL (design inputs)

Captured 2026-06-17 from the operator while completing G3c. This is **design
input**, not a spec — it shapes G-VER and a new security/DR workstream. Each item
is mapped to where it lands; much of the elevation *mechanism* already exists.

---

## The trust tiers (operator's framing)

1. **Read-only authorized** → "pull all tables allowed." = the public corpus
   (`public_records` via cold-sync, **built**) + the geospatial dataset
   (GEODATA_ORIGIN, **G3c**). No governance.
2. **Read-write peer** → can accept clients (a full node). Granted only when the
   operator meets **vetted requirements**: (a) a vetted/known operator identity,
   (b) **location-sensing** — runs from a location it can establish from its own
   environment, (c) runs a **signed copy of the code** (constitutional_version
   attestation), (d) **tenure** — has been up/honest for a while. On grant the
   peer **inherits the authority of the jurisdictions it operates in**, decided
   **independently per jurisdiction** that overlaps the operator's location (tied
   to their federation/mesh ID).
3. **Elevation authority:**
   - If a **standing government's election board (R-08)** exists for the
     jurisdiction → the board accepts the operator → they ascend (dev-auth for
     now) and inherit that jurisdiction's authority.
   - If **no standing board** → the **de-facto board of operators** must agree, on
     a **scaling threshold**: 2 boxes → 1 approves the other; 2 (elevated) → both
     (unanimity); 3 → 2/3; and so on (supermajority, unanimity floor at small N).

## The HA / recovery expectation

When **fully elevated**: Box A can go **down** and Box B **keeps functioning**
(serving its jurisdictions' clients). When Box A **returns**, it **recovers what
its elevated peer Box B handled** while it was gone, and that **propagates to the
read mesh**.

---

## Mapping to phases (what's built / building / to design)

### Already BUILT (Phase G core) — the elevation *mechanism* exists
- **`AuthorityFlipService`** (export/import flip = move `authoritative_server_id`)
  — the A→B authority handover.
- **`OperationalBundleService` (G5)** — the sealed `k_e` bundle that rides a flip.
- **`LocalAutonomyService` (G6)** — the GOVERNED elevation vote (dual supermajority:
  population + parent MJV). The "standing government accepts" path.
- **Patroni HA + `LeaderProbe`** — data-tier failover (A down → B continues).
- **FF&C sync + authority-instance-wins** — recovery + propagation to the read mesh.

So "elevate, A down/B up, A recovers, mesh propagates" is largely **realizable
today** with these + G-VER consent + the G3c intake — not greenfield.

### G3c (building now) — the elevation FRONT DOOR + GUI
- `read_write_requests` intake (a mirror requests RW).
- Host console surfaces RW requests; operator opens the governed vote / (dev) grant.
- Join-wizard negotiation (what-to-pull, relationship, the RW request).
- GEODATA_ORIGIN read-only dataset pull.

### G-VER (designed, to build) — versioning + the de-facto operator board
- The **scaling operator-consent** (1→approve, 2→unanimity, 3+→2/3) when no
  standing board — the MJV substrate already does supermajority; add the small-N
  unanimity floor + the "operators as board" anchor (R-08 bootstrap).
- **Signed-code attestation** (`constitutional_version`) as an elevation precondition.
- **Per-jurisdiction** elevation gated by legitimacy flags + location overlap.
- **Location-sensing**: ties to the existing residency/location pipeline + the
  operator's physical location → which jurisdictions overlap.

### NEW — a dedicated SECURITY / DR design round (largely RIG-GATED for real cert)
These are **not build-blind** items — adversarial security needs its own design
round before code, and most need the physical rig to certify:
- **Poisoned / hijacked node detection** + quarantine procedures (the mesh already
  detects hash-chain discontinuity; extend to behavior/version anomalies + a
  revoke/eject path).
- **Key rotation** procedures — federation identity key, operator device keys,
  join keys (rotate-and-re-pin across the mesh; the CRL pattern exists).
- **Disaster recovery** runbooks — restore-from-mesh, re-key, re-elevate.
- **Adversarial resistance** — Sybil operators (the consent threshold + vetting),
  malicious-elevated-peer containment, the autonomy-flip exception boundary.
- **Mesh SSL + memorable domains** — clients need HTTPS (the app requires a secure
  context for GPS etc.); mesh adoption + client entry points need reachable certs.
  Operator has **Cloudflare** domains + can mint keys/tokens. Likely: cloudflared
  tunnels (already in the Headscale runbook) + per-node subdomains + a directory of
  memorable names. **Operator executes the DNS/cert/secret steps; we provide the
  wiring + runbooks.**
- **Shared elevated secrets that SELF-HEAL under attack** — the hard one:
  distributed/threshold secret management (e.g. Shamir-split or a sealed
  re-key quorum) so a compromised node can be ejected + the shared secrets
  re-formed without a single point of capture. Needs careful design + rig cert.

---

## Build order implication

G3c (now) → **end-to-end test** → G-VER (elevation consent + signed-code +
per-jurisdiction) → the **security/DR/SSL design round** (then build, mostly
rig-gated). The SSL/domain/self-healing-secret work is operator-infra-heavy and
adversarial-critical — it gets a real design round, not a hasty build.
