# DNS broker futures — budget rails · DDNS · wildcard (design notes, NOT BUILT)

*Lane 2, Wave 2 (2026-07-29). The /operator/dns page (mockups/v3/operator/dns.html)
renders three capabilities the mockup itself labels future/stub. Per the marching
order these are DESIGN-NOTE items, never silent builds. The page shows each with an
explicit "not yet implemented / not yet enforced" badge — nothing is simulated.*

## 1. The budget rails (per-domain 50/7d · per-name 5/7d)

**Why:** Let's Encrypt rate-limits per registered domain; a flapping host that
re-requests daily can exhaust the whole domain's budget and lock every other node
out of renewal for a week.

**Design:** two rolling-week counters computed from the broker's own append-only
`issuances` ledger (which already records fqdn, domain, issued_at — the data
exists; only the counting/refusal is unbuilt):
- per registered domain: refuse issue when ≥50 issuances in the trailing 7 days;
- per exact fqdn: refuse when ≥5 in the trailing 7 days.
The refusal is PRE-FLIGHT — it sits between the A-record write and the ACME call
in `MeshCertBroker\Broker::issue`, so a refused request burns zero LE budget, and
it answers WITH REMEDIATION ("domain budget resets <date>; per-name budget free").
No schema change: `select count(*) from issuances where domain=? and issued_at>?`.

## 2. Dynamic DNS — a moving node re-points itself

**Why:** home nodes change IPs; today the only A-record write happens at issuance.

**Design:** a signed re-point request (same `federation.signed` grade as
cert-request): {fqdn, new_target, nonce, ts} signed by the SAME peer key the
grant named. The broker verifies the peer holds a live grant for that exact fqdn
(the grant store / issuances ledger is the authority), then calls the existing
`Cloudflare::upsertAddressRecord`. Same cert, no new issuance, consumes no LE
budget, and the budget rails never count it. Anti-replay via the existing
`seen_nonces` table.

## 3. Wildcard backup grants (*.domain)

**Why:** a fleet under one domain surviving a broker outage without per-name churn.

**Design (deliberately gated):** a DISTINCT grant kind (`cert_grant_wildcard`) —
never minted by the ungated per-name path. Authority-minted only, plus a
per-domain approval flag on the broker authorization, plus (optionally) the
Meter-C co-affected-peer consent bar where the domain is shared. The CSR pipeline
must grow DNS-01 for `*.domain` (today `GrantVerifier` + `assertCertCoversOnly`
enforce exactly one non-wildcard CN — that enforcement is a FEATURE and stays for
the per-name path). Fallback FSM: client needs a cert → per-name (ungated,
tried first) → wildcard backup ONLY if pre-approved.

## 4. Non-Cloudflare providers

Route53 / DigitalOcean: a provider interface extracted from `Cloudflare.php`
(two methods: upsertAddressRecord, writeAcmeChallenge). Manual: a "provider"
that returns the record for the operator to set by hand and polls for
propagation — always available, keeps the model working with zero automation.

## Build order (when scheduled)

Rails (pure read + refusal, no schema) → DDNS (one endpoint + verify) →
providers (interface extraction) → wildcard (trust-core work, operator's word
first — it touches the protected grant kinds).
