# DNS & Broker

To issue certs and make this box reachable by name, the broker writes **DNS records** (A-records
and DNS-01 TXT challenges) on your behalf, using a DNS-provider API token. Today the supported
provider is **Cloudflare**.

## The Cloudflare credential (write-only)

The DNS-edit token lives **only on this box**, in a gitignored, app-key-encrypted file
(`storage/app/broker/credentials.json`). It is **never** federated, logged, or shown in any UI —
the console shows only the domain, the zone, and the provenance (`local` vs `failover`), never
the token.

Store it (write-only) from the **Federation console** (`/federation`, operator-gated) → "Broker
credentials": enter the domain, the Cloudflare **zone ID**, and the API token. Or via the service
in tinker. To remove it, use "forget" on the same panel.

| Knob | Where | Tier | Notes |
|---|---|---|---|
| Credential store path | `CGA_BROKER_CREDENTIALS_PATH` (`config/cga.php`) | restart | Default `storage/app/broker/credentials.json`, `0600`. |
| Cloudflare token | the encrypted store above | — | Write-only; entered on the Federation console. **Never federates.** |

## Writing a DNS record

When you request a cert with `--target`, the broker upserts the **A/AAAA record** for the FQDN to
that IP *before* ACME issuance (fail-fast: if DNS fails, no ACME rate-limit is burned). The DNS-01
TXT challenge records are written and cleaned up automatically during issuance.

To register the box's address as a DNS A-record (e.g. after the WAN IP changes):

```bash
docker compose exec app php artisan mesh:request-cert <domain> <sub> --target=<IP> --local
```

(The broker writes the A-record only when `--target` is supplied.)

## Broker capability channels

The broker's powers are **governed capability channels** — surfaced on the console with their
state (established / requested / qualifiable / needs-config):

| Channel | What it grants |
|---|---|
| `broker.dns` | Write DNS records for the domain. |
| `broker.tls` | Issue TLS certs (ACME) for the domain. |
| `authority.grant` | Mint cert grants for *other* peers (you are the domain authority). |
| `client.serve` | Serve light-node clients. |

Establish one via `mesh:role qualify <channel>` → `request <channel>` → `approve --proposal=<id>`
(a solo box self-attests Meter A; a governed channel under a peer subtree also needs the
co-affected peers' consent — Meter C).

## Notes

- A **failover** credential is one a primary broker sealed to this box; it is re-encrypted at rest
  here exactly like a local one and is **never re-shared onward** (no transitive fan-out).
- Router/firewall/port-forward changes are **your** infrastructure — the broker only writes DNS.
  See `livekit.md` for the ports the box needs reachable.
