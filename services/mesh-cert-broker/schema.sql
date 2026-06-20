-- mesh-cert-broker MySQL schema. The broker auto-migrates on connect (Store::migrate), so this is for
-- ops reference / pre-provisioning only.

CREATE TABLE IF NOT EXISTS seen_nonces (
    nonce        VARCHAR(128) PRIMARY KEY,   -- anti-replay: each signed request's nonce, once
    peer_pubkey  VARCHAR(64)  NOT NULL,
    seen_at      BIGINT       NOT NULL
);

CREATE TABLE IF NOT EXISTS issuances (
    id                  VARCHAR(36)  PRIMARY KEY,   -- append-only audit of every cert issued
    fqdn                VARCHAR(255) NOT NULL,
    domain              VARCHAR(255) NOT NULL,
    peer_pubkey         VARCHAR(64)  NOT NULL,
    peer_server_id      VARCHAR(64),
    authority_server_id VARCHAR(64),
    target              VARCHAR(255),
    issued_at           BIGINT       NOT NULL,
    not_after           BIGINT
);
