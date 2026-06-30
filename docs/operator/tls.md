# TLS Certificates

This box can hold real TLS certificates (e.g. for `wss://` LiveKit signalling and the app
over HTTPS) issued via ACME / Let's Encrypt with a **DNS-01** challenge, brokered through the
mesh's Identity Broker role.

## Knobs

| Knob | Where | Tier | Notes |
|---|---|---|---|
| ACME provider | `CGA_BROKER_ACME_PROVIDER` (`config/cga.php` → `broker.acme.provider`) | restart | `lego` = real issuance via the `lego` CLI; `stub` = offline/no-op. |
| ACME account email | `CGA_BROKER_ACME_EMAIL` | restart | Required by Let's Encrypt for `lego`. |
| Staging | `CGA_BROKER_ACME_STAGING` | restart | `true` = LE **staging** (untrusted test certs, no rate-limit risk). Set `false` only when you're ready for real, rate-limited production certs. |
| DNS-01 resolvers | `CGA_BROKER_DNS_RESOLVERS` | restart | Resolver used to check DNS-01 propagation. Inside Docker, point at a real resolver (e.g. `1.1.1.1:53`) — the embedded `127.0.0.11` breaks `lego`'s SOA lookups. |
| Cert storage path | `CGA_BROKER_TLS_PATH` | restart | Default `storage/app/mesh-tls`. Certs land here as `<fqdn>.crt` / `<fqdn>.key`. |

## Installed certificates

The console lists every `*.crt` under the TLS path with its **expiry** and **days remaining**
(amber under 30 days, red if expired). Let's Encrypt certs are ~90-day; the cert grant TTL is
also 90 days to match the renewal window.

## Issuing / renewing a certificate

A cert needs a **grant** (the authority for the domain attests this box may hold a cert for the
FQDN) and then ACME issuance. The CLI front door:

```bash
docker compose exec app php artisan mesh:request-cert <domain> <subdomain> \
  --target=<this-box-public-or-LAN-IP> --local
```

- `--target` makes the broker also write the **A-record** (see `dns-broker.md`) so the FQDN
  resolves to this box before issuance — pass it, or DNS-01 succeeds but nothing resolves.
- `--local` issues via this box's in-mesh broker (needs the `broker.tls` channel established and
  a DNS credential present — see `dns-broker.md`).

The key never leaves the box; the cert installs under the TLS path. Renewal is the same command.

## Prerequisites

- The **`broker.tls`** capability channel must be established (Operations console → DNS & Broker →
  channels, or `/federation` role board). Establish it via `mesh:role qualify/request/approve broker.tls`.
- A **Cloudflare DNS credential** for the domain must be stored (write-only) — see `dns-broker.md`.

## Known gotchas

- **`docker compose ... key:generate --force` rotates `APP_KEY`** and would make the encrypted
  DNS credential (and other encrypted-at-rest values) undecryptable — never run `deploy.sh` on a
  box whose identity/credentials must be preserved.
- A real wildcard cert (`*.example.org`) covers every subdomain; the console shows it once under
  its CN.
