<?php

namespace App\Services;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Write/verify API for the append-only constitutional audit chain
 * (WF-SYS-04). The ONLY code path that inserts into `audit_log`.
 *
 * Chain construction:
 *   hash(n) = sha256( hash(n-1) || canonical_json(payload(n)) )
 * with the genesis row's prev_hash = 64 zeros (seeded by the
 * create_audit_log migration).
 *
 * canonical_json recursively sorts object keys (lists keep their order),
 * after a PHP-level encode/decode round trip so the hash computed at
 * append time matches the hash recomputed at verify time from the jsonb
 * column. Payload values should be JSON scalars, lists, and objects —
 * exotic float representations (e.g. 1e10) may render differently after
 * a Postgres jsonb round trip and are not supported payload values.
 *
 * Payloads must NEVER contain ballot content or raw locations —
 * commitments and counts only. Handlers are responsible for shaping
 * audit payloads accordingly; the engine additionally strips credential
 * material from rejection payloads.
 */
class AuditService
{
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Append one entry to the chain.
     *
     * Runs inside the caller's DB transaction when one is open (the
     * ConstitutionalEngine wraps mutation + audit atomically: no mutation
     * without its entry, no entry without its mutation). When no
     * transaction is open, one is created so the head-row lock is held
     * for the duration of the insert.
     *
     * The chain head is serialized with
     *   SELECT ... ORDER BY seq DESC LIMIT 1 FOR UPDATE
     * so concurrent appends cannot fork the chain.
     */
    public function append(
        string $module,
        string $event,
        array $payload,
        ?string $ref = null,
        ?string $actorId = null,
        ?string $jurisdictionId = null,
        bool $rejected = false,
        ?string $blockedReason = null,
    ): AuditEntry {
        $insert = function () use (
            $module, $event, $payload, $ref, $actorId, $jurisdictionId, $rejected, $blockedReason
        ): AuditEntry {
            $head = DB::selectOne('SELECT seq, hash FROM audit_log ORDER BY seq DESC LIMIT 1 FOR UPDATE');

            if ($head === null) {
                throw new RuntimeException('audit_log genesis row missing — run migrations.');
            }

            $canonical = self::canonicalJson($payload);
            $hash      = self::chainHash($head->hash, $canonical);

            $row = DB::selectOne(
                'INSERT INTO audit_log
                    (occurred_at, actor_user_id, module, event, ref, jurisdiction_id,
                     payload, prev_hash, hash, rejected, blocked_reason, created_at)
                 VALUES (now(), ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, now())
                 RETURNING *',
                [$actorId, $module, $event, $ref, $jurisdictionId, $canonical, $head->hash, $hash, $rejected, $blockedReason]
            );

            return (new AuditEntry)->newFromBuilder($row);
        };

        return DB::transactionLevel() > 0 ? $insert() : DB::transaction($insert);
    }

    /**
     * Walk the full chain recomputing every link.
     *
     * Verifies, per row: prev_hash equals the preceding row's hash, and
     * hash recomputes from prev_hash + canonical payload. The genesis row
     * is verified against the all-zeros prev_hash.
     *
     * @param  int|null  $fromSeq  Optional starting seq (the preceding row
     *                             anchors the walk; it is trusted as-is).
     * @return true|int  true when intact, otherwise the seq of the first
     *                   broken link.
     */
    public function verifyChain(?int $fromSeq = null): true|int
    {
        $expectedPrev = self::GENESIS_PREV_HASH;

        $query = DB::table('audit_log')
            ->select(['seq', 'payload', 'prev_hash', 'hash'])
            ->orderBy('seq');

        if ($fromSeq !== null && $fromSeq > 1) {
            $anchor = DB::table('audit_log')
                ->where('seq', '<', $fromSeq)
                ->orderByDesc('seq')
                ->value('hash');

            if ($anchor === null) {
                throw new RuntimeException("No audit entry precedes seq {$fromSeq} — cannot anchor verification.");
            }

            $expectedPrev = $anchor;
            $query->where('seq', '>=', $fromSeq);
        }

        foreach ($query->cursor() as $row) {
            if ($row->prev_hash !== $expectedPrev) {
                return (int) $row->seq;
            }

            $canonical = self::canonicalJson(json_decode($row->payload, true) ?? []);

            if (self::chainHash($row->prev_hash, $canonical) !== $row->hash) {
                return (int) $row->seq;
            }

            $expectedPrev = $row->hash;
        }

        return true;
    }

    /** Highest seq currently in the chain. */
    public function latestSeq(): int
    {
        return (int) DB::table('audit_log')->max('seq');
    }

    /** Total number of chain entries (gaps in seq are possible and harmless). */
    public function count(): int
    {
        return (int) DB::table('audit_log')->count();
    }

    // -------------------------------------------------------------------------
    // Pure hashing helpers (unit-testable without a database)
    // -------------------------------------------------------------------------

    /**
     * Canonical JSON: recursively key-sorted objects, lists kept in order,
     * unescaped slashes/unicode. MUST stay byte-identical with the inlined
     * copy in the create_audit_log migration (genesis row).
     */
    public static function canonicalJson(array $payload): string
    {
        // PHP-level round trip so append-time values normalize the same way
        // they will after a jsonb round trip (objects → assoc arrays, etc.).
        $normalized = json_decode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true
        ) ?? [];

        return json_encode(self::ksortRecursive($normalized), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** hash(n) = sha256(prev_hash || canonical_json(payload)). */
    public static function chainHash(string $prevHash, string $canonicalPayload): string
    {
        return hash('sha256', $prevHash . $canonicalPayload);
    }

    private static function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::ksortRecursive($item);
        }

        if (! $isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
