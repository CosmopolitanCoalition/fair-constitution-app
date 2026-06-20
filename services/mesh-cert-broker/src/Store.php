<?php

namespace MeshCertBroker;

use PDO;

/**
 * Broker state (the LAMP "M"): the anti-replay nonce ledger + the append-only issuance audit. PDO so the
 * same code runs on MySQL (production) or SQLite (local tests). Portable DDL; auto-migrates on connect.
 */
final class Store
{
    private PDO $pdo;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS seen_nonces (
                nonce VARCHAR(128) PRIMARY KEY,
                peer_pubkey VARCHAR(64) NOT NULL,
                seen_at BIGINT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS issuances (
                id VARCHAR(36) PRIMARY KEY,
                fqdn VARCHAR(255) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                peer_pubkey VARCHAR(64) NOT NULL,
                peer_server_id VARCHAR(64),
                authority_server_id VARCHAR(64),
                target VARCHAR(255),
                issued_at BIGINT NOT NULL,
                not_after BIGINT
            )'
        );
    }

    /** Record a nonce; FALSE if it was already seen (replay). Atomic via the PK. */
    public function consumeNonce(string $nonce, string $peerPubkey, int $now): bool
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO seen_nonces (nonce, peer_pubkey, seen_at) VALUES (?, ?, ?)');
            $stmt->execute([$nonce, $peerPubkey, $now]);

            return true;
        } catch (\PDOException) {
            return false; // duplicate PK = replay
        }
    }

    public function pruneNonces(int $olderThan): void
    {
        $this->pdo->prepare('DELETE FROM seen_nonces WHERE seen_at < ?')->execute([$olderThan]);
    }

    public function recordIssuance(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO issuances (id, fqdn, domain, peer_pubkey, peer_server_id, authority_server_id, target, issued_at, not_after)
             VALUES (:id, :fqdn, :domain, :peer_pubkey, :peer_server_id, :authority_server_id, :target, :issued_at, :not_after)'
        );
        $stmt->execute($row);
    }
}
