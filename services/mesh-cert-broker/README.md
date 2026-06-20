# mesh-cert-broker

A **mesh-authenticated, promotion-gated certificate + naming broker.** It runs on the one box that owns
the domain(s) and holds the Cloudflare token; a CGA peer that has been **promoted to accept client
connections** asks it for a real `*.<your-domain>` TLS cert. The token never leaves this box, never
reaches a volunteer, never appears in a response. Multi-domain by config. Pure PHP — drops onto a plain
LAMP box, no framework.

## Why it exists
A volunteer who pulls the CGA repo can't be handed a domain + a CA cert. Mesh peers authenticate by
**key** (Ed25519, pinned), not by name — so they need no certs to federate governance. But a node that
graduates to serving **browser clients** does need a real cert. This broker is that graduation step: when
a peer is promoted, the authority signs a **grant**, the peer signs a **request**, and the broker — and
only the broker — turns that into a real Let's Encrypt cert via Cloudflare DNS-01. Cert issuance follows
**legitimacy**, the same way authority over a jurisdiction does.

## The trust chain (what the broker enforces)
A cert is issued only if ALL hold (see `src/GrantVerifier.php`):
1. the request is signed by the **peer**'s Ed25519 key (the same key the mesh pins);
2. it carries a **grant** signed by an **authority** whose key is in *this domain's* authorized set;
3. the grant is unexpired and the request is fresh + nonce-unique (anti-replay);
4. the name is a well-formed `<subdomain>.<domain>` for a domain the broker serves;
5. the CSR asks for **exactly** that name and no other (no smuggled SANs).

The peer keeps its TLS private key — only a CSR (public) ever travels.

## Drop it on the LAMP / Azure box
1. **Requirements:** PHP 8.1+ with `sodium`, `openssl`, `curl`, `pdo` (mysql or sqlite). For real certs,
   the [`lego`](https://github.com/go-acme/lego/releases) binary on `PATH` (one download). The `openssl`
   CLI (for CSR SAN inspection).
2. **Docroot → `public/`.** Point the Apache vhost (the `auth.worldofstatecraft.org` subdomain you'll link)
   at `services/mesh-cert-broker/public`. Everything else stays outside the docroot.
3. **Config:** `cp config/domains.example.php config/domains.php` and fill it in:
   - `store_dsn/_user/_pass` — your MySQL (or `sqlite:/abs/path/broker.sqlite`).
   - `acme.provider = 'lego'`, `acme.email`, `acme.staging = true` (flip to `false` for real certs).
   - per domain: the **Cloudflare token** (scope: *Zone → DNS → Edit* on that zone only), the **zone id**,
     and the **authority public key(s)** — get it on the authority box with `php artisan federation:identity`
     (the `public_key`). Add more `domains` entries to grow/replace the ecosystem's naming roots.
   `config/domains.php` is gitignored — the token is never committed.
4. **MySQL:** the broker auto-creates its tables; `schema.sql` is provided for pre-provisioning.

## Test it (what you and I will run)
- **Logic, offline (no network, stub CA):** `php bin/selftest.php` — issues a cert on the happy path and
  refuses every attack (tampered grant, rogue authority, name-mismatch CSR, unknown domain, replay).
- **End-to-end against a running broker:** generate a signed request, then POST it:
  ```sh
  php bin/test-client.php config/domains.test.php var/req.json <domain> <subdomain> [target-ip]
  CB_CONFIG=$PWD/config/domains.test.php php -S 127.0.0.1:8099 -t public &
  curl -s -X POST --data-binary @var/req.json http://127.0.0.1:8099/   # → {fqdn, certificate, dns}
  ```
- **Real issuance:** with `acme.provider=lego` + `staging=true` and the token in place, the same POST
  returns a real (staging) Let's Encrypt cert and creates the A record. Flip `staging=false` for a trusted cert.

## The request protocol (for the CGA-side peer client)
`POST /` a JSON body — `grant` (signed by the authority), `grant_signature`, `csr` (PEM), `nonce`,
`requested_at` (epoch), `target` (optional A-record IP), and `request_signature` (the peer signs the
canonical JSON of everything except `request_signature`). The grant fields: `v, type:"cert_grant",
domain, subdomain, peer_pubkey, peer_server_id, authority_pubkey, authority_server_id, issued_at,
expires_at`. Canonical JSON + signatures are byte-compatible with the CGA's `AuditService::canonicalJson`
+ `InstanceIdentityService` (key-sorted, `UNESCAPED_SLASHES|UNESCAPED_UNICODE`, detached Ed25519, ORIGINAL
base64). Response: `{ fqdn, certificate (full-chain PEM), issued_at, dns: created|skipped|failed }`.

## Rotation
- **Cloudflare token:** create token-2 in Cloudflare → swap it in `config/domains.php` → done. Revoke
  token-1. No issued cert is affected. The token is scoped to one zone, so blast radius is one domain.
- **Authority keys:** add/remove entries in a domain's `authority_keys`. Removing a key instantly stops it
  from granting; rotation = add the new key, then drop the old.
- **Per-peer revocation:** cert lifetime is short (Let's Encrypt = 90d) and renewal **re-runs the grant
  check** — a de-promoted peer simply isn't granted again, so its cert lapses. (Revocation-by-expiry.)

## Still to wire (CGA side — the next step)
The broker is complete + tested. To close the loop, the CGA needs: (a) a peer command
(`php artisan mesh:request-cert`) that generates the CSR, builds the signed request, posts here, installs
the returned cert; and (b) the authority side that mints + signs the **grant** when a peer is promoted
(extend the G6 earned-autonomy / promotion flow). `bin/test-client.php` stands in for (a) until then.
