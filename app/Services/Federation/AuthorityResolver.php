<?php

namespace App\Services\Federation;

use Illuminate\Support\Facades\DB;

/**
 * THE single source of truth for "who is authoritative for a jurisdiction?"
 * (Phase G, G4). Reads ONLY `jurisdictions.authoritative_server_id` — the
 * Phase-F authority axis, UNCHANGED. It deliberately knows NOTHING about
 * leadership / clusters / Patroni: authority ≠ leadership (the cardinal
 * invariant), so a Patroni follower still presents `authoritative_server_id =
 * NULL` and resolves to OURS here. Keeping this class clear of leadership state
 * is what lets `FederationSyncService` delegate to it without tripping the
 * ClusterAuthoritySeparation grep pin.
 *
 * Two consumers share it:
 *   - `FederationSyncService::authorityDisposition` (ingest conflict resolution);
 *   - `WriteRouterService` (route a local write here-or-forward).
 */
class AuthorityResolver
{
    /** We hold authority: the jurisdiction row exists with a NULL authoritative_server_id. */
    public const OURS = '__ours__';

    /** No jurisdiction scope, or a jurisdiction this instance does not track. */
    public const UNTRACKED = '__untracked__';

    /**
     * Raw authority for a jurisdiction:
     *   OURS       — we are authoritative (authoritative_server_id IS NULL);
     *   UNTRACKED  — global/system scope, or a jurisdiction not in our table;
     *   <server_id>— the peer instance authoritative for it.
     *
     * The sentinels are non-UUID strings, so they can never collide with a real
     * server_id.
     */
    public function authorityFor(?string $jurisdictionId): string
    {
        if ($jurisdictionId === null) {
            return self::UNTRACKED;
        }

        $row = DB::table('jurisdictions')->where('id', $jurisdictionId)->first(['authoritative_server_id']);

        if ($row === null) {
            return self::UNTRACKED;
        }
        if ($row->authoritative_server_id === null) {
            return self::OURS;
        }

        return (string) $row->authoritative_server_id;
    }

    /**
     * Does THIS instance execute a write for the jurisdiction locally? True when
     * we own it (OURS) or there is nothing to forward to (UNTRACKED / system).
     */
    public function isLocalAuthority(?string $jurisdictionId): bool
    {
        $a = $this->authorityFor($jurisdictionId);

        return $a === self::OURS || $a === self::UNTRACKED;
    }

    /**
     * The peer server_id a write for this jurisdiction must be forwarded to, or
     * NULL when we execute it locally.
     */
    public function authoritativeServerIdFor(?string $jurisdictionId): ?string
    {
        $a = $this->authorityFor($jurisdictionId);

        return ($a === self::OURS || $a === self::UNTRACKED) ? null : $a;
    }
}
